<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Lifecycle;

use SplObjectStorage;
use SuperKernel\Pool\Constants\HealthCheckMode;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Pool\Exception\PoolException;
use Throwable;

/**
 * Tracks the borrowed connections of one pool with their reservations and completes their returns.
 *
 * @template T of ConnectionInterface
 *
 * @internal
 */
final readonly class BorrowedConnections
{
    /** @var SplObjectStorage<T, string> */
    private SplObjectStorage $connections;

    /**
     * @param IdleConnections<T>     $idle
     * @param ConnectionLifecycle<T> $lifecycle
     */
    public function __construct(
        private PoolGuard           $guard,
        private IdleConnections     $idle,
        private ConnectionLifecycle $lifecycle,
        private CapacityInterface   $backend,
        private HealthCheckMode     $healthChecks,
    )
    {
        $this->connections = new SplObjectStorage();
    }

    /** @param T $connection */
    public function track(ConnectionInterface $connection, string $reservation): void
    {
        $this->connections->offsetSet($connection, $reservation);
    }

    /**
     * Retain a reusable connection as idle or destroy it, then release its reservation unless cleanup failed.
     * Borrowed ownership is removed before health or cleanup callbacks run.
     *
     * @param T $connection
     *
     * @throws PoolException When the object is not currently borrowed from this pool or the context is invalid
     * @throws Throwable     When the return health check or resource cleanup fails
     */
    public function complete(ConnectionInterface $connection, bool $discard): void
    {
        $this->guard->assertOwner();
        if (!$this->connections->contains($connection)) {
            throw new PoolException('The connection is not currently borrowed from this pool.');
        }
        $reservation = $this->connections[$connection];
        $this->connections->detach($connection);
        try {
            $retain = !$this->guard->isClosed() && $this->backend->allowsIdleConnections()
                && !$discard;
            if ($retain && $this->healthChecks->onReturn()) {
                try {
                    $retain = $connection->isHealthy();
                } catch (Throwable $exception) {
                    try {
                        $this->lifecycle->destroy($connection, $reservation);
                    } catch (Throwable) {
                        // Preserve health-check failures when cleanup also fails.
                    }
                    throw $exception;
                }
            }
            if ($retain && !$this->guard->isClosed()) {
                $this->idle->add($connection);
            } else {
                $this->lifecycle->destroy($connection, $reservation);
            }
        } finally {
            $this->lifecycle->releaseCapacity($reservation);
        }
    }
}
