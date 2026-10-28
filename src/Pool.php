<?php

declare(strict_types=1);

namespace SuperKernel\Pool;

use InvalidArgumentException;
use SplObjectStorage;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Constants\HealthCheckMode;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Contract\ConnectionFactoryInterface;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Pool\Contract\FrequencyInterface;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Exception\InvalidPoolConfigurationException;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Runtime\ExecutionContext;
use Throwable;

use function array_any;
use function array_keys;
use function array_reverse;
use function count;
use function is_finite;
use function spl_object_id;

/**
 * @template T of ConnectionInterface
 *
 * @implements PoolInterface<T>
 */
final class Pool implements PoolInterface
{
    /** @var array<int, T> */
    private array $idle = [];

    /** @var SplObjectStorage<T, null> */
    private readonly SplObjectStorage $connections;

    /** @var SplObjectStorage<T, string> */
    private readonly SplObjectStorage $borrowed;

    /** @var array<int, array{connection: T, reservation: ?string, closing: bool}> */
    private array $pendingCleanup = [];

    private readonly string $owner;

    private readonly string $context;

    private int $allocated = 0;

    private bool $closed = false;

    private bool $maintaining = false;

    /** @var ConnectionFactoryInterface<T> */
    private readonly ConnectionFactoryInterface $factory;

    private readonly CapacityInterface $backend;

    private readonly HealthCheckMode $healthChecks;

    private readonly FrequencyInterface $frequency;

    /**
     * @param PoolOption<T> $option
     *
     * @throws InvalidPoolConfigurationException When pool limits are invalid or backend capacity differs
     * @throws PoolException                     When an owner identity cannot be generated
     */
    public function __construct(private readonly PoolOption $option)
    {
        $capacity = $option->maxConnections();
        $timeout = $option->waitTimeout();
        $this->backend = $option->capacity();
        if (1 > $capacity || !is_finite($timeout) || 0 > $timeout || $this->backend->capacity() !== $capacity) {
            throw new InvalidPoolConfigurationException(
                'Positive matching capacity and a finite timeout are required.',
            );
        }
        $this->factory = $option->factory();
        $this->healthChecks = $option->healthChecks();
        $this->frequency = $option->frequency();
        $this->connections = new SplObjectStorage();
        $this->borrowed = new SplObjectStorage();
        $this->owner = ExecutionContext::token();
        $this->context = $this->backend->contextId();
    }

    public function acquire(?float $timeout = null): ConnectionInterface
    {
        $timeout ??= $this->option->waitTimeout();
        if (!is_finite($timeout) || 0 > $timeout) {
            throw new InvalidArgumentException('The connection wait timeout must be finite and non-negative.');
        }
        $this->assertOpen();
        $this->assertOwner();
        $this->frequency->recordBorrow();
        $reservation = $this->backend->reserve($timeout, $this->owner);
        $connection = null;
        try {
            $this->assertOpen();
            foreach (array_reverse(array_keys($this->idle)) as $key) {
                $connection = $this->idle[$key] ?? null;
                unset($this->idle[$key]);
                if (null === $connection) {
                    continue;
                }
                if (!$this->healthChecks->onBorrow() || $connection->isHealthy()) {
                    break;
                }
                $unhealthy = $connection;
                $connection = null;
                $this->destroy($unhealthy, $reservation);
                $this->assertOpen();
            }
            $this->assertOpen();
            if (null === $connection) {
                $connection = $this->createConnection();
                $this->assertOpen();
                $connection->connect();
            }
            $this->assertOpen();
            $this->borrowed[$connection] = $reservation;

            return $connection;
        } catch (Throwable $exception) {
            try {
                if (null !== $connection) {
                    $this->destroy($connection, $reservation);
                }
            } catch (Throwable) {
                // Preserve the acquisition failure if resource cleanup also fails.
            } finally {
                $this->releaseCapacity($reservation);
            }

            throw $exception;
        }
    }

    public function release(ConnectionInterface $connection): void
    {
        $this->returnConnection($connection, false);
    }

    public function discard(ConnectionInterface $connection): void
    {
        $this->returnConnection($connection, true);
    }

    public function maintain(int $minimumIdle = 0): void
    {
        if (0 > $minimumIdle || $minimumIdle > $this->backend->capacity()) {
            throw new InvalidArgumentException('The idle minimum must be between zero and capacity.');
        }
        $this->assertOwner();
        if (0 < $minimumIdle && !$this->backend->allowsIdleConnections()) {
            throw new PoolException('Shared backends retire owner-local resources and cannot retain idle connections.');
        }
        if ($this->maintaining) {
            return;
        }
        $this->maintaining = true;
        try {
            $lowFrequency = !$this->isClosed() && $this->backend->allowsIdleConnections()
                && $this->frequency->isLowFrequency();
            $failure = $this->retryCleanup();
            $targetIdle = $lowFrequency || !$this->backend->allowsIdleConnections()
                ? $minimumIdle : $this->backend->capacity();
            foreach (array_keys($this->idle) as $key) {
                if ($this->isClosed()) {
                    break;
                }
                $reservation = $this->tryReserve();
                if (null === $reservation) {
                    break;
                }
                $connection = $this->idle[$key] ?? null;
                unset($this->idle[$key]);
                try {
                    if (null !== $connection) {
                        if ($lowFrequency && count($this->idle) >= $minimumIdle) {
                            $this->destroy($connection, $reservation);
                        } else {
                            $this->checkIdle($connection, $reservation);
                        }
                    }
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                } finally {
                    $this->releaseCapacity($reservation);
                }
            }
            while (!$this->isClosed() && count($this->idle) < $targetIdle
                && $this->allocated < $this->backend->capacity()) {
                $reservation = $this->tryReserve();
                if (null === $reservation) {
                    break;
                }
                $connection = null;
                try {
                    $connection = $this->createConnection();
                    $this->assertOpen();
                    $connection->connect();
                    $this->assertOpen();
                    $this->idle[spl_object_id($connection)] = $connection;
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                    try {
                        if (null !== $connection) {
                            $this->destroy($connection, $reservation);
                        }
                    } catch (Throwable) {
                        // The original establishment failure remains actionable.
                    }
                    break;
                } finally {
                    $this->releaseCapacity($reservation);
                }
            }
        } finally {
            $this->maintaining = false;
        }
        if (null !== $failure) {
            throw $failure;
        }
    }

    public function close(): void
    {
        if ($this->context !== $this->backend->contextId()) {
            throw new PoolException('Create each resource-owning pool in its own process or thread.');
        }
        // The backend marks shutdown before waking waiters and validates initialized runtime access.
        if (!$this->closed) {
            $this->backend->close();
        }
        $this->closed = true;
        $idle = $this->idle;
        $this->idle = [];
        $failure = $this->retryCleanup();
        foreach ($idle as $connection) {
            try {
                $this->destroy($connection);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }
        if (null !== $failure) {
            throw $failure;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed || $this->backend->isClosed();
    }

    /** @throws Throwable When returning or cleaning up a resource fails */
    private function returnConnection(ConnectionInterface $connection, bool $discard): void
    {
        $this->assertOwner();
        if (!$this->borrowed->contains($connection)) {
            throw new PoolException('The connection is not currently borrowed from this pool.');
        }
        $reservation = $this->borrowed[$connection];
        $this->borrowed->detach($connection);
        try {
            $retain = !$this->isClosed() && $this->backend->allowsIdleConnections()
                && !$discard;
            if ($retain && $this->healthChecks->onReturn()) {
                try {
                    $retain = $connection->isHealthy();
                } catch (Throwable $exception) {
                    try {
                        $this->destroy($connection, $reservation);
                    } catch (Throwable) {
                        // Preserve health-check failures when cleanup also fails.
                    }
                    throw $exception;
                }
            }
            if ($retain && !$this->isClosed()) {
                $this->idle[spl_object_id($connection)] = $connection;
            } else {
                $this->destroy($connection, $reservation);
            }
        } finally {
            $this->releaseCapacity($reservation);
        }
    }

    /** @throws Throwable When an idle health check or resource cleanup fails */
    private function checkIdle(ConnectionInterface $connection, string $reservation): void
    {
        try {
            $healthy = $connection->isHealthy();
        } catch (Throwable $exception) {
            try {
                $this->destroy($connection, $reservation);
            } catch (Throwable) {
                // Preserve the health-check failure.
            }
            throw $exception;
        }
        if ($healthy && !$this->isClosed()) {
            $this->idle[spl_object_id($connection)] = $connection;
        } else {
            $this->destroy($connection, $reservation);
        }
    }

    /** @throws PoolException When backend coordination fails */
    private function tryReserve(): ?string
    {
        try {
            return $this->backend->reserve(0, $this->owner);
        } catch (PoolTimeoutException|PoolClosedException) {
            return null;
        }
    }

    /**
     * @return T
     *
     * @throws Throwable When creation fails or the factory returns an already owned connection
     */
    private function createConnection(): ConnectionInterface
    {
        ++$this->allocated;
        try {
            $connection = $this->factory->create();
            if ($this->connections->contains($connection)) {
                throw new PoolException('The factory returned a connection already owned by this pool.');
            }
            $this->connections[$connection] = null;

            return $connection;
        } catch (Throwable $exception) {
            --$this->allocated;

            throw $exception;
        }
    }

    /** @throws Throwable When closing the connection fails */
    private function destroy(ConnectionInterface $connection, ?string $reservation = null): void
    {
        $key = spl_object_id($connection);
        $this->pendingCleanup[$key] = [
            'connection'  => $connection,
            'reservation' => $reservation,
            'closing'     => true,
        ];
        try {
            $connection->close();
        } catch (Throwable $exception) {
            $this->pendingCleanup[$key]['closing'] = false;

            throw $exception;
        }
        unset($this->pendingCleanup[$key]);
        $this->connections->detach($connection);
        --$this->allocated;
    }

    /** @throws PoolException When a completed operation cannot release its capacity */
    private function releaseCapacity(string $reservation): void
    {
        if (array_any(
            $this->pendingCleanup,
            static fn(array $pending): bool => $pending['reservation'] === $reservation,
        )) {
            return;
        }
        $this->backend->release($reservation, $this->owner);
    }

    /** @return Throwable|null The first cleanup failure after attempting every inactive pending resource */
    private function retryCleanup(): ?Throwable
    {
        $failure = null;
        foreach (array_keys($this->pendingCleanup) as $key) {
            $pending = $this->pendingCleanup[$key] ?? null;
            if (null === $pending || $pending['closing']) {
                continue;
            }
            try {
                $this->destroy($pending['connection'], $pending['reservation']);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            } finally {
                if (null !== $pending['reservation']) {
                    $this->releaseCapacity($pending['reservation']);
                }
            }
        }

        return $failure;
    }

    /** @throws PoolClosedException When the pool or shared backend has closed */
    private function assertOpen(): void
    {
        if ($this->isClosed()) {
            throw new PoolClosedException('The connection pool is closed.');
        }
    }

    /** @throws PoolException When accessing resources from a foreign execution unit */
    private function assertOwner(): void
    {
        $this->backend->assertContext();
        if ($this->context !== $this->backend->contextId()) {
            throw new PoolException('Create each resource-owning pool in its own process or thread.');
        }
    }
}
