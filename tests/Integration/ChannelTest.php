<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Integration;

use InvalidArgumentException;
use stdClass;
use SuperKernel\Pool\Channel\SwooleChannel;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Tests\Support\CoroutineTestCase;
use Throwable;

use const INF;
use const NAN;

final class ChannelTest extends CoroutineTestCase
{
    public function testRejectsNonPositiveCapacityBeforeEnteringTheRuntime(): void
    {
        foreach ([
            0,
            -1,
        ] as $capacity) {
            try {
                new SwooleChannel($capacity);
                self::fail('A channel must reject non-positive capacity.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('positive', $exception->getMessage());
            }
        }
    }

    /** @throws Throwable When an unexpected channel operation fails */
    public function testUnusedChannelConstructionAndClosureArePassive(): void
    {
        $channel = new SwooleChannel(3);
        self::assertSame(3, $channel->capacity());
        self::assertFalse($channel->isClosed());
        $channel->close();
        $channel->close();
        self::assertTrue($channel->isClosed());
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testPreservesObjectIdentityNullAndFalseInFifoOrder(): void
    {
        $this->runCoroutine(function (): void {
            $channel = new SwooleChannel(3);
            $value = new stdClass();
            $channel->push($value);
            $channel->push(null);
            $channel->push(false);
            self::assertSame($value, $channel->pop(0.0));
            self::assertNull($channel->pop(0.0));
            self::assertFalse($channel->pop(0.0));
            $channel->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testAFullChannelRejectsTheValueWithoutLosingBufferedData(): void
    {
        $this->runCoroutine(function (): void {
            $channel = new SwooleChannel(1);
            $first = new stdClass();
            $channel->push($first);
            try {
                $channel->push(new stdClass());
                self::fail('A full channel must reject the value without waiting.');
            } catch (PoolException $exception) {
                self::assertStringContainsString('full', $exception->getMessage());
            }
            self::assertSame($first, $channel->pop(0.0));
            $channel->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testBothImmediateAndBoundedWaitsTimeOut(): void
    {
        $this->runCoroutine(function (): void {
            $channel = new SwooleChannel(1);
            foreach ([
                0.0,
                0.001,
            ] as $timeout) {
                try {
                    $channel->pop($timeout);
                    self::fail('An empty channel must time out.');
                } catch (PoolTimeoutException) {
                    self::assertFalse($channel->isClosed());
                }
            }
            $channel->close();
        });
    }

    /** @throws Throwable When an unexpected channel operation fails */
    public function testRejectsInvalidTimeoutsBeforeEnteringTheRuntime(): void
    {
        $channel = new SwooleChannel(1);
        foreach ([
            -1.0,
            INF,
            NAN,
        ] as $timeout) {
            try {
                $channel->pop($timeout);
                self::fail('Invalid timeouts must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('finite and non-negative', $exception->getMessage());
            }
        }
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testClosedChannelsRejectReadsIncludingBufferedValuesAndRejectWrites(): void
    {
        $this->runCoroutine(function (): void {
            $channel = new SwooleChannel(1);
            $channel->push(new stdClass());
            $channel->close();
            try {
                $channel->pop(0.0);
                self::fail('A closed channel must not deliver buffered values.');
            } catch (PoolClosedException) {
                self::assertTrue($channel->isClosed());
            }
            $this->expectException(PoolClosedException::class);
            $channel->push(new stdClass());
        });
    }

    /** @throws Throwable When the missing coroutine is rejected */
    public function testChannelOperationsRequireACoroutine(): void
    {
        $channel = new SwooleChannel(1);
        $this->expectException(PoolException::class);
        $this->expectExceptionMessageIsOrContains('active Swoole coroutine');
        $channel->push(new stdClass());
    }
}
