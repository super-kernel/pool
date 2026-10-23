<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

use InvalidArgumentException;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;

/** Coordinates capacity while connections and object-based returns remain local to their pool. */
interface CapacityInterface
{
    /** @return positive-int Maximum outstanding reservations, including creation and cleanup */
    public function capacity(): int;

    /**
     * Reserve capacity for an owner. The timeout bounds capacity waiting; zero means an immediate attempt.
     *
     * @return string Internal capacity token, retained by Pool rather than exposed to connection borrowers
     *
     * @throws InvalidArgumentException When the timeout or owner is invalid
     * @throws PoolClosedException      When shutdown prevents acquisition
     * @throws PoolTimeoutException     When capacity is exhausted
     * @throws PoolException            When the execution context or shared state is unavailable
     */
    public function reserve(float $timeout, string $owner): string;

    /**
     * Release a reservation once, after returning a reusable connection or completing resource cleanup.
     * Failed cleanup keeps its reservation until a retry succeeds, including after shutdown.
     *
     * @throws PoolException When the reservation or owner is invalid
     */
    public function release(string $reservation, string $owner): void;

    /** @return non-negative-int Reservable slots, not ready idle connections; zero after shutdown */
    public function available(): int;

    /** Whether this backend permits worker-local idle connection retention. */
    public function allowsIdleConnections(): bool;

    /** @throws PoolException When called outside the supported execution context */
    public function assertContext(): void;

    /** Identify the resource-owning execution unit; coroutines in one worker share an identity. */
    public function contextId(): string;

    /** Reject acquisitions and wake or notify waiters; allow outstanding reservations to be released. */
    public function close(): void;

    public function isClosed(): bool;
}
