<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Integration;

use RuntimeException;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Capacity\ProcessCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Frequency\SmartLowFrequency;
use SuperKernel\Pool\Maintenance\SwooleTimerScheduler;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\CoroutineTestCase;
use Swoole\Coroutine\Channel;
use Throwable;

use function count;

final class LifecycleTest extends CoroutineTestCase
{
    /** @throws Throwable When borrowing, returning or replenishing a connection fails */
    public function testReturnsRestoreCapacityBeforeMaintenanceReplacesDiscardedConnections(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $capacity = new CoroutineCapacity(3);
            $pool = new Pool(new PoolOption($factory, $capacity, 3));
            $pool->maintain();
            self::assertCount(3, $factory->created);
            self::assertSame(3, $capacity->capacity());
            self::assertSame(3, $capacity->available());

            $connection = $pool->acquire();
            self::assertInstanceOf(Connection::class, $connection);
            self::assertSame(2, $capacity->available());
            $factory->healthCheck = static function (Connection $candidate) use ($connection): bool {
                self::assertNotSame($connection, $candidate);

                return true;
            };
            $pool->maintain();
            self::assertCount(3, $factory->created);
            self::assertSame(2, $capacity->available());

            $factory->healthCheck = null;
            $pool->release($connection);
            self::assertSame(3, $capacity->available());
            self::assertSame($connection, $pool->acquire());
            $connection->healthy = false;
            $pool->release($connection);
            self::assertTrue($connection->closed);
            self::assertSame(3, $capacity->available());
            self::assertCount(3, $factory->created);

            $pool->maintain();
            self::assertCount(4, $factory->created);
            self::assertSame(3, $capacity->capacity());
            $pool->close();
        });
    }

    /** @throws Throwable When idle validation or replacement fails */
    public function testBorrowSkipsEveryUnhealthyCandidateBeforeCreatingAnotherConnection(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(3), 3));
            $pool->maintain();
            $factory->created[1]->healthy = false;
            $factory->created[2]->healthy = false;

            $connection = $pool->acquire();
            self::assertSame($factory->created[0], $connection);
            self::assertCount(3, $factory->created);
            self::assertCount(2, $factory->closed);
            $pool->release($connection);
            $pool->close();
        });
    }

    /** @throws Throwable When replacing all unhealthy idle connections fails */
    public function testBorrowCreatesOneReplacementAfterExhaustingUnhealthyIdleConnections(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(2), 2));
            $pool->maintain();
            foreach ($factory->created as $connection) {
                $connection->healthy = false;
            }
            $replacement = $pool->acquire(0);
            self::assertSame($factory->created[2], $replacement);
            self::assertCount(3, $factory->created);
            self::assertCount(2, $factory->closed);
            $pool->release($replacement);
            $pool->close();
        });
    }

    /** @throws Throwable When demand changes or background replenishment fails */
    public function testRenewedDemandReplenishesCapacityAfterLowFrequencyShrinking(): void
    {
        $this->runCoroutine(static function (): void {
            $now = 0.0;
            $frequency = new SmartLowFrequency(0.2, 10.0, static function () use (&$now): float {
                return $now;
            });
            $factory = new ConnectionFactory();
            $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(3), 3, frequency: $frequency));
            $pool->maintain(1);
            self::assertCount(3, $factory->created);
            $now = 20.0;
            $pool->maintain(1);
            self::assertCount(2, $factory->closed);
            for ($index = 0; 3 > $index; ++$index) {
                $connection = $pool->acquire();
                $pool->release($connection);
            }
            $borrowed = $pool->acquire();
            $pool->maintain(1);
            self::assertCount(5, $factory->created);
            self::assertCount(2, $factory->closed);
            $pool->release($borrowed);
            $pool->close();
        });
    }

    /** @throws Throwable When maintenance cannot recover after connection establishment fails */
    public function testFailedReplenishmentLeavesARealDeficitForTheNextPass(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $capacity = new CoroutineCapacity(2);
            $pool = new Pool(new PoolOption($factory, $capacity, 2));
            $failure = new RuntimeException('establishment failed');
            $failed = new Connection();
            $failed->connector = static fn(): never => throw $failure;
            $factory->creator = static fn(): Connection => $failed;
            try {
                $pool->maintain();
                self::fail('Failed establishment must propagate without manufacturing an idle connection.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
                self::assertTrue($failed->closed);
                self::assertSame(2, $capacity->available());
                self::assertCount(1, $factory->created);
            }
            $factory->creator = null;
            $pool->maintain();
            self::assertCount(3, $factory->created);
            $pool->close();
        });
    }

    /** @throws Throwable When partial establishment cleanup or recovery fails */
    public function testAcquisitionFailureRetainsItsSlotUntilPartialConnectionCleanupSucceeds(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $capacity = new CoroutineCapacity(1);
            $pool = new Pool(new PoolOption($factory, $capacity, 1));
            $failure = new RuntimeException('establishment failed');
            $failed = new Connection();
            $failed->connector = static fn(): never => throw $failure;
            $factory->creator = static fn(): Connection => $failed;
            $cleanupFailure = new RuntimeException('cleanup failed');
            $factory->closer = static fn(): never => throw $cleanupFailure;
            try {
                $pool->acquire(0);
                self::fail('Acquisition must report the original establishment failure.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
                self::assertSame(0, $capacity->available());
            }
            try {
                $pool->maintain();
                self::fail('The cleanup failure must remain visible on the next maintenance pass.');
            } catch (RuntimeException $exception) {
                self::assertSame($cleanupFailure, $exception);
                self::assertCount(1, $factory->created);
                self::assertSame(0, $capacity->available());
            }
            $factory->creator = null;
            $factory->closer = null;
            $pool->maintain();
            self::assertTrue($failed->closed);
            self::assertCount(2, $factory->created);
            self::assertSame(1, $capacity->available());
            $pool->close();
        });
    }

    /** @throws Throwable When a shared capacity participant cannot recover its failed cleanup */
    public function testFailedSharedCleanupBlocksOtherOwnersUntilTheConnectionIsClosed(): void
    {
        $this->runCoroutine(static function (): void {
            $capacity = new ProcessCapacity(1);
            $factory = new ConnectionFactory();
            $first = new Pool(new PoolOption($factory, $capacity, 1));
            $otherFactory = new ConnectionFactory();
            $second = new Pool(new PoolOption($otherFactory, $capacity, 1));
            $connection = $first->acquire();
            $failure = new RuntimeException('cleanup failed');
            $factory->closer = static fn(): never => throw $failure;
            try {
                $first->release($connection);
                self::fail('Shared connections must finish cleanup before restoring capacity.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            try {
                $second->acquire(0);
                self::fail('Another owner must not reuse an occupied slot.');
            } catch (PoolTimeoutException) {
                self::assertSame([], $otherFactory->created);
            }
            $factory->closer = null;
            $first->maintain();
            self::assertCount(1, $factory->created);
            $replacement = $second->acquire(0);
            $second->release($replacement);
            self::assertSame(1, $capacity->available());
            $first->close();
            $second->close();
        });
    }

    /** @throws Throwable When scheduled replenishment or its error callback fails */
    public function testTimerReplenishesDiscardedConnectionsWithoutAnExplicitIdleMinimum(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $capacity = new CoroutineCapacity(1);
            $pool = new Pool(new PoolOption($factory, $capacity, 1));
            $connection = $pool->acquire();
            $pool->discard($connection);
            $ready = new Channel(1);
            $factory->creator = static function () use ($ready): Connection {
                $replacement = new Connection();
                $replacement->connector = static function () use ($ready): void {
                    $ready->push(true);
                };

                return $replacement;
            };
            $failure = null;
            $scheduler = new SwooleTimerScheduler($pool, 1, static function (
                Throwable $exception,
            ) use (&$failure): void {
                $failure = $exception;
            });
            try {
                $scheduler->start();
                self::assertTrue($ready->pop(1));
                $replacement = $pool->acquire();
                self::assertNotSame($connection, $replacement);
                self::assertSame(2, count($factory->created));
                $pool->release($replacement);
                self::assertNull($failure);
            } finally {
                $scheduler->stop();
                $pool->close();
            }
        });
    }
}
