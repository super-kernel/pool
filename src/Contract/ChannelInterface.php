<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

use InvalidArgumentException;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;

/** @template T */
interface ChannelInterface
{
    /** @return positive-int Fixed queue capacity */
    public function capacity(): int;

    /**
     * Enqueue one value without waiting. On failure, the value was not delivered.
     * Null and false are valid payloads; failures are represented by exceptions.
     *
     * @param T $value
     *
     * @throws PoolClosedException When the channel is closed
     * @throws PoolException       When the channel is full or its execution context is unavailable
     */
    public function push(mixed $value): void;

    /**
     * Take the oldest queued value, waiting up to the given number of seconds.
     * Zero means no waiting. A closed channel rejects reads, including buffered values.
     *
     * @return T
     *
     * @throws InvalidArgumentException When the timeout is negative or non-finite
     * @throws PoolClosedException      When the channel closes before a value is received
     * @throws PoolTimeoutException     When no value arrives before the timeout
     * @throws PoolException            When waiting is canceled or the execution context is unavailable
     */
    public function pop(float $timeout): mixed;

    /**
     * Permanently reject operations and wake all waiting readers. Repeated calls are harmless.
     * Payload cleanup remains with the owner; channel shutdown does not close connections.
     *
     * @throws PoolException When the execution context is unavailable for an initialized channel
     */
    public function close(): void;

    public function isClosed(): bool;
}
