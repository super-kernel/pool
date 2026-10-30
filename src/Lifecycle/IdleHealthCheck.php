<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Lifecycle;

use SuperKernel\Pool\Contract\ConnectionInterface;
use Throwable;

/**
 * Checks a reserved idle connection during maintenance and keeps it idle only while healthy and open.
 *
 * @template T of ConnectionInterface
 *
 * @internal
 */
final readonly class IdleHealthCheck
{
    /**
     * @param ConnectionLifecycle<T> $lifecycle
     * @param IdleConnections<T>     $idle
     */
    public function __construct(
        private ConnectionLifecycle $lifecycle,
        private PoolGuard           $guard,
        private IdleConnections     $idle,
    )
    {
    }

    /**
     * Retire the connection instead when it is unhealthy, its check fails or the pool has closed meanwhile.
     *
     * @param T $connection
     *
     * @throws Throwable When the health check or resource cleanup fails
     */
    public function check(ConnectionInterface $connection, string $reservation): void
    {
        try {
            $healthy = $connection->isHealthy();
        } catch (Throwable $exception) {
            try {
                $this->lifecycle->destroy($connection, $reservation);
            } catch (Throwable) {
                // Preserve the health-check failure.
            }
            throw $exception;
        }
        if ($healthy && !$this->guard->isClosed()) {
            $this->idle->add($connection);
        } else {
            $this->lifecycle->destroy($connection, $reservation);
        }
    }
}
