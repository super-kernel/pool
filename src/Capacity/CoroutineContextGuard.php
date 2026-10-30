<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Runtime\ExecutionContext;
use Swoole\Coroutine;

/**
 * Restricts a coroutine capacity to coroutines of the process or thread that created it.
 *
 * @internal
 */
final class CoroutineContextGuard
{
    /**
     * @param string $context Execution unit identity captured when the capacity was created
     *
     * @throws PoolException When called from another process or thread, or outside a Swoole coroutine
     */
    public static function assert(string $context): void
    {
        if ($context !== ExecutionContext::id()) {
            throw new PoolException('A coroutine backend cannot be shared between processes or threads.');
        }
        if (0 > Coroutine::getCid()) {
            throw new PoolException('Connection pool operations require an active Swoole coroutine.');
        }
    }
}
