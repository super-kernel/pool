<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

use InvalidArgumentException;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use Throwable;

/** @template T of ConnectionInterface */
interface PoolInterface
{
    /**
     * Borrow an exclusive connection. Null selects the configured wait timeout; zero means no waiting.
     * Capacity waiting is bounded; connection I/O deadlines belong to the adapter.
     * Return the object to this pool exactly once, after all use and session cleanup have finished.
     * Borrowed connections remain counted but are never inspected or closed by idle maintenance.
     *
     * @return T
     *
     * @throws InvalidArgumentException When the timeout is negative or non-finite
     * @throws PoolClosedException      When shutdown prevents acquisition
     * @throws PoolTimeoutException     When capacity is exhausted
     * @throws PoolException            When the context is invalid, canceled, or the factory duplicates an owned object
     * @throws Throwable                When frequency tracking, connection creation or validation fails
     */
    public function acquire(?float $timeout = null): ConnectionInterface;

    /**
     * Return a connection for reuse after restoring its session state and stopping all use.
     * Reject objects that are not currently borrowed from this pool, including repeated returns.
     * Callers must not retain references across borrows; object identity cannot distinguish an old borrower
     * from the current borrower after the same connection is acquired again.
     * Shared backends retire owner-local resources before restoring capacity.
     * Failed cleanup retains capacity and is retried by maintenance or a later close(), never by returning twice.
     *
     * @param T $connection
     *
     * @throws PoolException When the object is not currently borrowed from this pool or the context is invalid
     * @throws Throwable     When return validation or cleanup fails
     */
    public function release(ConnectionInterface $connection): void;

    /**
     * Return a broken or non-resettable connection for destruction. Cleanup precedes capacity restoration.
     * The connection must be currently borrowed from this pool in its owning process or thread.
     * Successful cleanup restores an allocation slot, not an already usable connection.
     * Failed cleanup keeps that slot occupied until maintenance or close() successfully retries it.
     *
     * @param T $connection
     *
     * @throws PoolException When the object is not currently borrowed from this pool or the context is invalid
     * @throws Throwable     When cleanup fails
     */
    public function discard(ConnectionInterface $connection): void;

    /**
     * Retry failed cleanup, check idle resources and replenish unoccupied connection capacity.
     * At low frequency, shrink and replenish only to the requested idle minimum instead.
     * Borrowed connections, callback operations and failed cleanup limit replenishment below either target.
     * Never inspect a borrowed connection. Overlapping passes are skipped; I/O deadlines belong to adapters.
     * Shared backends retire resources in their owner and require a zero idle minimum.
     *
     * @throws InvalidArgumentException When the idle minimum is outside the capacity range
     * @throws PoolException            When the execution context or retention policy is incompatible
     * @throws Throwable                When frequency evaluation fails, or resource maintenance fails after remaining
     *                                  resources are attempted
     */
    public function maintain(int $minimumIdle = 0): void;

    /**
     * Permanently close the backend for all attached participants and reject waiters.
     * Close every local idle connection, propagating the first failure. Borrowed connections remain usable
     * until callers return them to their owning pool, which then destroys them.
     * Repeated calls retry failed cleanup without closing a successfully retired connection again.
     *
     * @throws PoolException When the owning execution context is unavailable
     * @throws Throwable     When local resource cleanup fails
     */
    public function close(): void;

    public function isClosed(): bool;
}
