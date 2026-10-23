<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Runtime;

use Random\RandomException;
use SuperKernel\Pool\Exception\PoolException;
use Swoole\Coroutine;
use Swoole\Thread;

use function bin2hex;
use function class_exists;
use function getmypid;
use function random_bytes;
use function usleep;

/** @internal */
final class ExecutionContext
{
    public static function id(): string
    {
        return getmypid() . ':' . (class_exists(Thread::class) ? Thread::getId() : 0);
    }

    /** @throws PoolException When capacity token generation fails */
    public static function token(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (RandomException $exception) {
            throw new PoolException('Unable to generate a capacity token.', previous: $exception);
        }
    }

    /** @throws PoolException When a waiting coroutine is canceled */
    public static function pause(float $seconds): void
    {
        if (0 <= Coroutine::getCid()) {
            if (!Coroutine::sleep($seconds)) {
                throw new PoolException('The capacity wait was canceled.');
            }
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
