<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Integration;

use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\CoroutineTestCase;
use SuperKernel\Pool\Tests\Support\PoolFactory;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

final class IndependentPoolsTest extends CoroutineTestCase
{
    /** @throws Throwable When a scenario or connection operation fails */
    public function testPoolsKeepTheirLimitsConnectionsAndShutdownSeparate(): void
    {
        $this->runCoroutine(function (): void {
            $firstFactory = new ConnectionFactory();
            $secondFactory = new ConnectionFactory();
            $firstPool = PoolFactory::create($firstFactory, 1, 0.0);
            $secondPool = PoolFactory::create($secondFactory, 2, 0.5);

            $first = $firstPool->acquire();
            $second = $secondPool->acquire();
            $third = $secondPool->acquire();
            try {
                $firstPool->acquire();
                self::fail('The first pool must enforce its own capacity.');
            } catch (PoolTimeoutException) {
                self::assertCount(1, $firstFactory->created);
                self::assertCount(2, $secondFactory->created);
            }
            try {
                $secondPool->release($first);
                self::fail('A connection cannot be returned to a different pool.');
            } catch (PoolException) {
                self::assertFalse($first->closed);
            }

            $secondPool->release($second);
            $firstPool->close();
            $firstPool->release($first);
            self::assertTrue($first->closed);
            self::assertFalse($secondPool->isClosed());
            self::assertSame([], $secondFactory->closed);
            self::assertSame($second, $secondPool->acquire());
            $secondPool->release($second);
            $secondPool->release($third);
            $secondPool->close();
            self::assertCount(1, $firstFactory->closed);
            self::assertCount(2, $secondFactory->closed);
        });
    }

    /** @throws Throwable When a scenario or coroutine callback fails */
    public function testClosingOnePoolDoesNotWakeOrCancelAnotherPoolsWaiter(): void
    {
        $this->runCoroutine(function (): void {
            $firstPool = PoolFactory::create(new ConnectionFactory(), 1, 0.0);
            $secondPool = PoolFactory::create(new ConnectionFactory(), 1, 0.5);
            $first = $firstPool->acquire();
            $second = $secondPool->acquire();
            $done = new Channel(1);
            $this->startCoroutine(function () use ($secondPool, $second, $done): void {
                $connection = $secondPool->acquire();
                self::assertSame($second, $connection);
                $secondPool->release($connection);
                $done->push(true);
            });

            Coroutine::sleep(0.001);
            $firstPool->close();
            $firstPool->release($first);
            self::assertTrue($done->isEmpty());
            self::assertFalse($second->closed);
            $secondPool->release($second);
            self::assertTrue($done->pop(1.0));
            $secondPool->close();
        });
    }
}
