<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Integration;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Frequency\ConstantFrequency;
use SuperKernel\Pool\Frequency\SmartLowFrequency;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\CoroutineTestCase;
use Throwable;

final class FrequencyMaintenanceTest extends CoroutineTestCase
{
    /** @throws Throwable When borrowing or maintenance fails */
    public function testLowFrequencyRetiresOnlyIdleConnectionsAboveTheMinimum(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $pool = new Pool(
                new PoolOption($factory, new CoroutineCapacity(3), 3, frequency: new ConstantFrequency(0.0)),
            );
            $pool->maintain(3);
            $connection = $pool->acquire();
            self::assertInstanceOf(Connection::class, $connection);

            $pool->maintain(1);

            self::assertCount(3, $factory->created);
            self::assertCount(1, $factory->closed);
            self::assertFalse($connection->closed);
            $idle = $pool->acquire();
            self::assertCount(3, $factory->created);
            self::assertNotSame($connection, $idle);
            $pool->discard($idle);
            $pool->discard($connection);
            $pool->close();
        });
    }

    /** @throws Throwable When borrowing or maintenance fails */
    public function testDefaultConstantFrequencyPreservesHealthyIdleConnections(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(3), 3));
            $pool->maintain(3);
            $pool->maintain(1);

            self::assertCount(3, $factory->created);
            self::assertSame([], $factory->closed);
            $pool->close();
        });
    }

    /** @throws Throwable When borrowing or maintenance fails */
    public function testSmartFrequencyUsesBorrowDemandAndShrinksAfterAnIdlePeriod(): void
    {
        $this->runCoroutine(static function (): void {
            $now = 0.0;
            $frequency = new SmartLowFrequency(0.25, 10.0, static function () use (&$now): float {
                return $now;
            });
            $factory = new ConnectionFactory();
            $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(3), 3, frequency: $frequency));
            $pool->maintain(3);
            for ($index = 0; 10 > $index; ++$index) {
                $connection = $pool->acquire();
                $pool->release($connection);
            }
            self::assertEqualsWithDelta(1.0, $frequency->frequency(), 1e-12);
            $now = 10.0;
            $pool->maintain();
            self::assertSame([], $factory->closed);
            $now = 20.0;
            $pool->maintain(1);
            self::assertCount(2, $factory->closed);
            self::assertCount(3, $factory->created);
            $connection = $pool->acquire();
            self::assertInstanceOf(Connection::class, $connection);
            self::assertFalse($connection->closed);
            self::assertCount(3, $factory->created);
            $pool->discard($connection);
            $pool->close();
        });
    }

    /** @throws Throwable When borrowing or frequency evaluation fails */
    public function testCapacityTimeoutsCountAsDemandButInvalidAndClosedBorrowsDoNot(): void
    {
        $this->runCoroutine(static function (): void {
            $frequency = new SmartLowFrequency(windowSeconds: 10.0, clock: static fn(): float => 0.0);
            $pool = new Pool(
                new PoolOption(new ConnectionFactory(), new CoroutineCapacity(1), 1, frequency: $frequency),
            );
            $connection = $pool->acquire();
            try {
                $pool->acquire(0);
                self::fail('Capacity exhaustion must reject the second borrow.');
            } catch (PoolTimeoutException) {
                self::assertEqualsWithDelta(0.2, $frequency->frequency(), 1e-12);
            }
            try {
                $pool->acquire(-1.0);
                self::fail('Invalid timeouts must be rejected before recording demand.');
            } catch (InvalidArgumentException) {
                self::assertEqualsWithDelta(0.2, $frequency->frequency(), 1e-12);
            }
            $pool->close();
            try {
                $pool->acquire();
                self::fail('A closed pool must reject borrowing before recording demand.');
            } catch (PoolClosedException) {
                self::assertEqualsWithDelta(0.2, $frequency->frequency(), 1e-12);
            }
            $pool->discard($connection);
        });
    }

    /** @throws Throwable When idle cleanup or capacity recovery fails */
    public function testLowFrequencyCleanupRetainsFailedReservationsUntilTheNextSuccessfulPass(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $backend = new CoroutineCapacity(3);
            $pool = new Pool(new PoolOption($factory, $backend, 3, frequency: new ConstantFrequency(0.0)));
            $pool->maintain(3);
            $failure = new RuntimeException('idle cleanup failed');
            $factory->closer = static fn(): never => throw $failure;
            try {
                $pool->maintain();
                self::fail('Cleanup failures must propagate after remaining idle connections are attempted.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
                self::assertCount(3, $factory->closed);
                self::assertSame(0, $backend->available());
            }
            $factory->closer = null;
            $pool->maintain(1);
            self::assertCount(4, $factory->created);
            self::assertCount(6, $factory->closed);
            self::assertSame(3, $backend->available());
            $pool->close();
        });
    }

    /** @throws Throwable When frequency evaluation or maintenance fails */
    public function testFrequencyFailureLeavesIdleConnectionsAndMaintenanceRetryable(): void
    {
        $this->runCoroutine(static function (): void {
            $now = 0.0;
            $frequency = new SmartLowFrequency(windowSeconds: 10.0, clock: static function () use (&$now): float {
                return $now;
            });
            $factory = new ConnectionFactory();
            $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(2), 2, frequency: $frequency));
            $pool->maintain(2);
            $now = -1.0;
            try {
                $pool->maintain();
                self::fail('An invalid clock must fail before connections are retired.');
            } catch (LogicException) {
                self::assertSame([], $factory->closed);
            }
            $now = 20.0;
            $pool->maintain();
            self::assertCount(2, $factory->closed);
            $pool->close();
        });
    }

    /** @throws Throwable When returning a connection after shutdown fails */
    public function testClosedPoolsAcceptObjectReturnsWithoutEvaluatingFrequency(): void
    {
        $this->runCoroutine(static function (): void {
            $now = 0.0;
            $frequency = new SmartLowFrequency(clock: static function () use (&$now): float {
                return $now;
            });
            $factory = new ConnectionFactory();
            $backend = new CoroutineCapacity(1);
            $pool = new Pool(new PoolOption($factory, $backend, 1, frequency: $frequency));
            $connection = $pool->acquire();
            self::assertInstanceOf(Connection::class, $connection);
            $pool->close();
            self::assertFalse($connection->closed);
            $now = -1.0;

            $pool->maintain();
            $pool->release($connection);

            self::assertTrue($connection->closed);
            self::assertCount(1, $factory->closed);
            self::assertSame(0, $backend->available());
        });
    }
}
