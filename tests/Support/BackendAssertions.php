<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use Throwable;

use function hrtime;

use const INF;
use const NAN;

final class BackendAssertions
{
    /** @throws Throwable When reservation invariants fail */
    public static function verify(CapacityInterface $backend): void
    {
        $reservation = $backend->reserve(0, 'owner');
        Assert::assertSame(0, $backend->available());
        try {
            $backend->release($reservation, 'foreign-owner');
            Assert::fail('Only the reserving owner may release capacity.');
        } catch (PoolException) {
            Assert::assertSame(0, $backend->available());
        }
        try {
            $backend->release('unknown', 'owner');
            Assert::fail('Unknown reservations must be rejected.');
        } catch (PoolException) {
            Assert::assertSame(0, $backend->available());
        }
        foreach ([
            -1.0,
            INF,
            NAN,
        ] as $timeout) {
            try {
                $backend->reserve($timeout, 'owner');
                Assert::fail('Invalid timeouts must fail without changing reservations.');
            } catch (InvalidArgumentException) {
                Assert::assertSame(0, $backend->available());
            }
        }
        foreach ([
            0.0,
            0.002,
        ] as $timeout) {
            $start = hrtime(true);
            try {
                $backend->reserve($timeout, 'waiter');
                Assert::fail('Exhausted capacity must time out.');
            } catch (PoolTimeoutException) {
                Assert::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
                Assert::assertSame(0, $backend->available());
            }
        }
        $backend->release($reservation, 'owner');
        Assert::assertSame(1, $backend->available());
        try {
            $backend->release($reservation, 'owner');
            Assert::fail('A released reservation cannot restore capacity twice.');
        } catch (PoolException) {
            Assert::assertSame(1, $backend->available());
        }
        $last = $backend->reserve(0, 'owner');
        $backend->close();
        $backend->release($last, 'owner');
        Assert::assertSame(0, $backend->available());
        try {
            $backend->reserve(0, 'owner');
            Assert::fail('Closed capacity must remain unavailable.');
        } catch (PoolClosedException) {
            Assert::assertTrue($backend->isClosed());
        }
    }
}
