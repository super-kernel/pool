<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Channel;

use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use Swoole\Coroutine;

/**
 * Rejects channel operations after shutdown or outside a Swoole coroutine.
 *
 * @internal
 */
final class ChannelGuard
{
    /**
     * @param bool $closed Whether the channel has been closed
     *
     * @throws PoolClosedException When the channel has closed
     */
    public static function assertOpen(bool $closed): void
    {
        if ($closed) {
            throw new PoolClosedException('The connection channel is closed.');
        }
    }

    /** @throws PoolException When no Swoole coroutine is active */
    public static function assertCoroutine(): void
    {
        if (0 > Coroutine::getCid()) {
            throw new PoolException('Connection channel operations require an active Swoole coroutine.');
        }
    }
}
