<?php

declare(strict_types=1);

namespace SuperKernel\Pool;

use InvalidArgumentException;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Constants\HealthCheckMode;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Pool\Contract\FrequencyInterface;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Exception\InvalidPoolConfigurationException;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Lifecycle\BorrowedConnections;
use SuperKernel\Pool\Lifecycle\CleanupRetry;
use SuperKernel\Pool\Lifecycle\ConnectionLifecycle;
use SuperKernel\Pool\Lifecycle\IdleConnections;
use SuperKernel\Pool\Lifecycle\IdleHealthCheck;
use SuperKernel\Pool\Lifecycle\PoolGuard;
use SuperKernel\Pool\Runtime\ExecutionContext;
use Throwable;

use function array_reverse;
use function is_finite;

/**
 * @template T of ConnectionInterface
 *
 * @implements PoolInterface<T>
 */
final class Pool implements PoolInterface
{
    /** @var IdleConnections<T> */
    private readonly IdleConnections $idle;

    /** @var BorrowedConnections<T> */
    private readonly BorrowedConnections $borrowed;

    /** @var ConnectionLifecycle<T> */
    private readonly ConnectionLifecycle $lifecycle;

    /** @var IdleHealthCheck<T> */
    private readonly IdleHealthCheck $idleHealthCheck;

    private readonly PoolGuard $guard;

    private readonly string $owner;

    private bool $maintaining = false;

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
        $factory = $option->factory();
        $this->healthChecks = $option->healthChecks();
        $this->frequency = $option->frequency();
        $this->owner = ExecutionContext::token();
        $this->guard = new PoolGuard($this->backend, $this->backend->contextId());
        $this->idle = new IdleConnections();
        $this->lifecycle = new ConnectionLifecycle($factory, $this->backend, $this->owner);
        $this->idleHealthCheck = new IdleHealthCheck($this->lifecycle, $this->guard, $this->idle);
        $this->borrowed = new BorrowedConnections(
            $this->guard,
            $this->idle,
            $this->lifecycle,
            $this->backend,
            $this->healthChecks,
        );
    }

    public function acquire(?float $timeout = null): ConnectionInterface
    {
        $timeout ??= $this->option->waitTimeout();
        if (!is_finite($timeout) || 0 > $timeout) {
            throw new InvalidArgumentException('The connection wait timeout must be finite and non-negative.');
        }
        $this->guard->assertOpen();
        $this->guard->assertOwner();
        $this->frequency->recordBorrow();
        $reservation = $this->backend->reserve($timeout, $this->owner);
        $connection = null;
        try {
            $this->guard->assertOpen();
            foreach (array_reverse($this->idle->keys()) as $key) {
                $connection = $this->idle->take($key);
                if (null === $connection) {
                    continue;
                }
                if (!$this->healthChecks->onBorrow() || $connection->isHealthy()) {
                    break;
                }
                $unhealthy = $connection;
                $connection = null;
                $this->lifecycle->destroy($unhealthy, $reservation);
                $this->guard->assertOpen();
            }
            $this->guard->assertOpen();
            if (null === $connection) {
                $connection = $this->lifecycle->create();
                $this->guard->assertOpen();
                $connection->connect();
            }
            $this->guard->assertOpen();
            $this->borrowed->track($connection, $reservation);

            return $connection;
        } catch (Throwable $exception) {
            try {
                if (null !== $connection) {
                    $this->lifecycle->destroy($connection, $reservation);
                }
            } catch (Throwable) {
                // Preserve the acquisition failure if resource cleanup also fails.
            } finally {
                $this->lifecycle->releaseCapacity($reservation);
            }

            throw $exception;
        }
    }

    public function release(ConnectionInterface $connection): void
    {
        $this->borrowed->complete($connection, false);
    }

    public function discard(ConnectionInterface $connection): void
    {
        $this->borrowed->complete($connection, true);
    }

    public function maintain(int $minimumIdle = 0): void
    {
        if (0 > $minimumIdle || $minimumIdle > $this->backend->capacity()) {
            throw new InvalidArgumentException('The idle minimum must be between zero and capacity.');
        }
        $this->guard->assertOwner();
        if (0 < $minimumIdle && !$this->backend->allowsIdleConnections()) {
            throw new PoolException('Shared backends retire owner-local resources and cannot retain idle connections.');
        }
        if ($this->maintaining) {
            return;
        }
        $this->maintaining = true;
        try {
            $lowFrequency = !$this->guard->isClosed() && $this->backend->allowsIdleConnections()
                && $this->frequency->isLowFrequency();
            $failure = CleanupRetry::retry($this->lifecycle);
            $targetIdle = $lowFrequency || !$this->backend->allowsIdleConnections()
                ? $minimumIdle : $this->backend->capacity();
            foreach ($this->idle->keys() as $key) {
                if ($this->guard->isClosed()) {
                    break;
                }
                try {
                    $reservation = $this->backend->reserve(0, $this->owner);
                } catch (PoolTimeoutException|PoolClosedException) {
                    $reservation = null;
                }
                if (null === $reservation) {
                    break;
                }
                $connection = $this->idle->take($key);
                try {
                    if (null !== $connection) {
                        if ($lowFrequency && $this->idle->count() >= $minimumIdle) {
                            $this->lifecycle->destroy($connection, $reservation);
                        } else {
                            $this->idleHealthCheck->check($connection, $reservation);
                        }
                    }
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                } finally {
                    $this->lifecycle->releaseCapacity($reservation);
                }
            }
            while (!$this->guard->isClosed() && $this->idle->count() < $targetIdle
                && $this->lifecycle->allocated() < $this->backend->capacity()) {
                try {
                    $reservation = $this->backend->reserve(0, $this->owner);
                } catch (PoolTimeoutException|PoolClosedException) {
                    $reservation = null;
                }
                if (null === $reservation) {
                    break;
                }
                $connection = null;
                try {
                    $connection = $this->lifecycle->create();
                    $this->guard->assertOpen();
                    $connection->connect();
                    $this->guard->assertOpen();
                    $this->idle->add($connection);
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                    try {
                        if (null !== $connection) {
                            $this->lifecycle->destroy($connection, $reservation);
                        }
                    } catch (Throwable) {
                        // The original establishment failure remains actionable.
                    }
                    break;
                } finally {
                    $this->lifecycle->releaseCapacity($reservation);
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
        $this->guard->close();
        $idle = $this->idle->drain();
        $failure = CleanupRetry::retry($this->lifecycle);
        foreach ($idle as $connection) {
            try {
                $this->lifecycle->destroy($connection);
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
        return $this->guard->isClosed();
    }
}
