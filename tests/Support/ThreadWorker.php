<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Support;

use RuntimeException;
use SuperKernel\Pool\Capacity\ThreadCapacity;
use SuperKernel\Pool\Exception\PoolClosedException;
use Swoole\Thread;
use Swoole\Thread\Lock;
use Swoole\Thread\Map;
use Throwable;

final class ThreadWorker
{
    public static function run(): void
    {
        /** @var array{Map, Lock, Map, string} $arguments */
        $arguments = Thread::getArguments();
        [$state, $mutex, $metrics, $mode] = $arguments;
        try {
            $backend = new ThreadCapacity($state, $mutex);
            if ('contend' === $mode) {
                SharedWorker::contend($backend, static function () use ($metrics): int {
                    $metrics->incr('total');

                    return $metrics->incr('active');
                }, static fn(): int => $metrics->decr('active'));
            } elseif ('close' === $mode) {
                try {
                    $backend->reserve(2, 'waiter');
                    throw new RuntimeException('A closed backend accepted a waiter.');
                } catch (PoolClosedException) {
                    $metrics['closed'] = true;
                }
            }
        } catch (Throwable $exception) {
            $metrics['error'] = $exception->getMessage();
        }
    }
}
