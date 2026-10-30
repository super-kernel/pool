<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Lifecycle;

use SplObjectStorage;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Contract\ConnectionFactoryInterface;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Pool\Exception\PoolException;
use Throwable;

use function array_any;
use function array_keys;
use function spl_object_id;

/**
 * Creates and destroys the connections of one pool, counts their allocations and retains the capacity of cleanup
 * that has not succeeded yet.
 *
 * @template T of ConnectionInterface
 *
 * @internal
 */
final class ConnectionLifecycle
{
    /** @var SplObjectStorage<T, null> */
    private readonly SplObjectStorage $connections;

    /** @var array<int, array{connection: T, reservation: ?string, closing: bool}> */
    private array $pendingCleanup = [];

    private int $allocated = 0;

    /** @param ConnectionFactoryInterface<T> $factory */
    public function __construct(
        private readonly ConnectionFactoryInterface $factory,
        private readonly CapacityInterface          $backend,
        private readonly string                     $owner,
    )
    {
        $this->connections = new SplObjectStorage();
    }

    /**
     * @return T
     *
     * @throws Throwable When creation fails or the factory returns an already owned connection
     */
    public function create(): ConnectionInterface
    {
        ++$this->allocated;
        try {
            $connection = $this->factory->create();
            if ($this->connections->contains($connection)) {
                throw new PoolException('The factory returned a connection already owned by this pool.');
            }
            $this->connections->offsetSet($connection);

            return $connection;
        } catch (Throwable $exception) {
            --$this->allocated;

            throw $exception;
        }
    }

    /**
     * Close a connection; a failed close stays pending with its reservation until a retry succeeds.
     *
     * @param T $connection
     *
     * @throws Throwable When closing the connection fails
     */
    public function destroy(ConnectionInterface $connection, ?string $reservation = null): void
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

    /**
     * Release a reservation to the backend unless a pending cleanup still retains it.
     *
     * @throws PoolException When a completed operation cannot release its capacity
     */
    public function releaseCapacity(string $reservation): void
    {
        if (array_any(
            $this->pendingCleanup,
            static fn(array $pending): bool => $pending['reservation'] === $reservation,
        )) {
            return;
        }
        $this->backend->release($reservation, $this->owner);
    }

    /** @return int Created connections that are not destroyed yet, including creation in progress */
    public function allocated(): int
    {
        return $this->allocated;
    }

    /** @return list<int> Object identities of the cleanups that are pending at the time of the call */
    public function pendingKeys(): array
    {
        return array_keys($this->pendingCleanup);
    }

    /** @return array{connection: T, reservation: ?string, closing: bool}|null */
    public function pending(int $key): ?array
    {
        return $this->pendingCleanup[$key] ?? null;
    }
}
