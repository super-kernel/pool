<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Integration;

use InvalidArgumentException;
use RuntimeException;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\CoroutineTestCase;
use SuperKernel\Pool\Tests\Support\PoolFactory;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function hrtime;
use function max;

final class PoolTest extends CoroutineTestCase
{
    /** @throws Throwable When a scenario or callback fails */
    public function testReusesIdleConnectionsBeforeCreatingMoreBelowTheLimit(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 5);
            $connection = $pool->acquire();
            self::assertSame(1, $connection->connects);
            for ($index = 0; 12 > $index; ++$index) {
                $pool->release($connection);
                self::assertSame($connection, $pool->acquire());
            }
            self::assertCount(1, $factory->created);
            self::assertSame(1, $connection->connects);
            $pool->release($connection);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testConnectionEstablishmentFailureClosesTheObjectAndRestoresCapacity(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $failed = new Connection();
            $failure = new RuntimeException('Connection establishment failed.');
            $failed->connector = static function () use ($failure): void {
                throw $failure;
            };
            $factory->creator = static fn(): Connection => $failed;
            $pool = PoolFactory::create($factory, 1, 0.0);
            try {
                $pool->acquire();
                self::fail('Connection establishment must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            self::assertTrue($failed->closed);
            self::assertSame([
                $failed,
            ], $factory->closed);
            $factory->creator = null;
            $replacement = $pool->acquire();
            self::assertNotSame($failed, $replacement);
            $pool->release($replacement);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testExplicitReconnectKeepsTheBorrowedObjectOwnedByThePool(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1);
            $connection = $pool->acquire();
            $connection->reconnect();
            self::assertSame(2, $connection->connects);
            self::assertTrue($connection->isHealthy());
            $pool->release($connection);
            self::assertSame($connection, $pool->acquire());
            $pool->release($connection);
            $pool->close();
            self::assertCount(1, $factory->created);
            self::assertCount(2, $factory->closed);
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testReusesHealthyConnectionsAndReplacesUnhealthyOnes(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1);
            $first = $pool->acquire();
            $pool->release($first);
            self::assertSame($first, $pool->acquire());
            $first->healthy = false;
            $pool->release($first);
            $replacement = $pool->acquire();
            self::assertNotSame($first, $replacement);
            self::assertTrue($first->closed);
            self::assertCount(2, $factory->created);
            $pool->release($replacement);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testRejectsForeignAndDuplicateReturnsWithoutChangingCapacity(): void
    {
        $this->runCoroutine(function (): void {
            $pool = PoolFactory::create(new ConnectionFactory(), 1);
            $connection = $pool->acquire();
            foreach ([
                'release',
                'discard',
            ] as $method) {
                try {
                    $pool->{$method}(new Connection());
                    self::fail('A foreign connection must be rejected.');
                } catch (PoolException $exception) {
                    self::assertStringContainsString('not currently borrowed', $exception->getMessage());
                }
            }
            $pool->release($connection);
            foreach ([
                'release',
                'discard',
            ] as $method) {
                try {
                    $pool->{$method}($connection);
                    self::fail('A duplicate return must be rejected.');
                } catch (PoolException $exception) {
                    self::assertStringContainsString('not currently borrowed', $exception->getMessage());
                }
            }
            self::assertSame($connection, $pool->acquire());
            $pool->discard($connection);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testCreationFailureRestoresCapacity(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $failure = new RuntimeException('Connection creation failed.');
            $factory->creator = static fn(): Connection => throw $failure;
            $pool = PoolFactory::create($factory, 1, 0.0);
            try {
                $pool->acquire();
                self::fail('Creation must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            $factory->creator = null;
            $connection = $pool->acquire();
            self::assertCount(1, $factory->created);
            $pool->release($connection);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testHealthFailurePreservesTheCauseWhenCleanupAlsoFails(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1, 0.0);
            $first = $pool->acquire();
            $pool->release($first);
            $failure = new RuntimeException('Health check failed.');
            $factory->healthCheck = static fn(Connection $connection): bool => throw $failure;
            $factory->closer = static function (Connection $connection) use ($first): void {
                self::assertSame($first, $connection);
                throw new RuntimeException('Cleanup failed.');
            };
            try {
                $pool->acquire();
                self::fail('The health check must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            self::assertSame([
                $first,
            ], $factory->closed);
            $factory->healthCheck = null;
            $factory->closer = null;
            $pool->maintain();
            $next = $pool->acquire();
            self::assertNotSame($first, $next);
            $pool->release($next);
            $pool->close();
        });
    }

    /**
     * Maintenance retries a fixture close through callbacks that PhpStorm does not track.
     *
     * @throws Throwable When a scenario or callback fails
     *
     * @noinspection PhpConditionAlreadyCheckedInspection
     */
    public function testDiscardRetainsCapacityUntilFailedCleanupIsRetried(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1, 0.0);
            $connection = $pool->acquire();
            $failure = new RuntimeException('Closing failed.');
            $factory->closer = static function (Connection $candidate) use ($connection, $failure): void {
                self::assertSame($connection, $candidate);
                throw $failure;
            };
            try {
                $pool->discard($connection);
                self::fail('Closing must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            try {
                $pool->acquire(0);
                self::fail('Failed cleanup must continue occupying capacity.');
            } catch (PoolTimeoutException) {
                self::assertCount(1, $factory->created);
                self::assertFalse($connection->closed);
            }
            $factory->closer = null;
            $pool->maintain();
            self::assertTrue($connection->closed);
            $next = $pool->acquire();
            self::assertNotSame($connection, $next);
            $pool->release($next);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testDuplicateFactoryConnectionsDoNotDestroyAnExistingBorrower(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 2, 0.0);
            $first = $pool->acquire();
            $factory->creator = static fn(): Connection => $first;
            try {
                $pool->acquire();
                self::fail('A duplicate factory connection must be rejected.');
            } catch (PoolException $exception) {
                self::assertStringContainsString('already owned', $exception->getMessage());
            }
            self::assertFalse($first->closed);
            $factory->creator = null;
            $second = $pool->acquire();
            self::assertNotSame($first, $second);
            $pool->release($first);
            $pool->release($second);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testConcurrentCreationAndBorrowingNeverExceedCapacity(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $creating = 0;
            $peakCreation = 0;
            $factory->creator = static function () use (&$creating, &$peakCreation): Connection {
                ++$creating;
                $peakCreation = max($creating, $peakCreation);
                Coroutine::sleep(0.005);
                --$creating;

                return new Connection();
            };
            $pool = PoolFactory::create($factory, 2);
            $done = new Channel(12);
            $active = 0;
            $peakActive = 0;
            for ($index = 0; 12 > $index; ++$index) {
                $this->startCoroutine(function () use ($pool, $done, &$active, &$peakActive): void {
                    try {
                        $connection = $pool->acquire();
                        ++$active;
                        $peakActive = max($active, $peakActive);
                        Coroutine::sleep(0.001);
                        --$active;
                        $pool->release($connection);
                    } finally {
                        $done->push(true);
                    }
                });
            }
            for ($index = 0; 12 > $index; ++$index) {
                self::assertTrue($done->pop(1.0));
            }
            self::assertSame(2, $peakCreation);
            self::assertSame(2, $peakActive);
            self::assertCount(2, $factory->created);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testTimeoutAndImmediateAcquisitionLeaveBorrowedConnectionsIntact(): void
    {
        $this->runCoroutine(function (): void {
            $pool = PoolFactory::create(new ConnectionFactory(), 1, 0.015);
            $connection = $pool->acquire();
            $started = hrtime(true);
            try {
                $pool->acquire();
                self::fail('Acquisition must time out.');
            } catch (PoolTimeoutException) {
                self::assertGreaterThanOrEqual(0.01, (hrtime(true) - $started) / 1_000_000_000);
            }
            try {
                $pool->acquire(0.0);
                self::fail('An immediate acquisition must fail while exhausted.');
            } catch (PoolTimeoutException) {
                self::assertFalse($connection->closed);
            }
            try {
                $pool->acquire(-1.0);
                self::fail('Negative timeouts must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('finite and non-negative', $exception->getMessage());
            }
            $pool->release($connection);
            self::assertSame($connection, $pool->acquire(0.0));
            $pool->release($connection);
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testDiscardWakesAWaiterOnlyAfterResourceCleanup(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1);
            $first = $pool->acquire();
            $done = new Channel(1);
            $factory->closer = static function (Connection $connection) use ($factory): void {
                Coroutine::sleep(0.005);
                self::assertCount(1, $factory->created);
                self::assertSame($factory->created[0], $connection);
            };
            $this->startCoroutine(function () use ($pool, $first, $done): void {
                try {
                    $next = $pool->acquire();
                    self::assertTrue($first->closed);
                    self::assertNotSame($first, $next);
                    $pool->release($next);
                } finally {
                    $done->push(true);
                }
            });
            $pool->discard($first);
            self::assertTrue($done->pop(1.0));
            $factory->closer = null;
            $pool->close();
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testCloseWakesEveryWaiterAndDefersBorrowedConnectionCleanup(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 2);
            $borrowed = $pool->acquire();
            $other = $pool->acquire();
            $done = new Channel(3);
            for ($index = 0; 3 > $index; ++$index) {
                $this->startCoroutine(function () use ($pool, $done): void {
                    try {
                        $pool->acquire();
                        self::fail('Closing must interrupt waiting.');
                    } catch (PoolClosedException) {
                        self::assertTrue($pool->isClosed());
                    } finally {
                        $done->push(true);
                    }
                });
            }
            $pool->close();
            for ($index = 0; 3 > $index; ++$index) {
                self::assertTrue($done->pop(1.0));
            }
            self::assertFalse($borrowed->closed);
            self::assertFalse($other->closed);
            $pool->release($borrowed);
            $pool->discard($other);
            $pool->close();
            self::assertCount(2, $factory->closed);
            self::assertTrue($borrowed->closed);
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testCloseAttemptsAllIdleConnectionsAfterACleanupFailure(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 2);
            $first = $pool->acquire();
            $second = $pool->acquire();
            $pool->release($first);
            $pool->release($second);
            $failure = new RuntimeException('First cleanup failed.');
            $factory->closer = static function (Connection $connection) use ($first, $failure): void {
                if ($connection === $first) {
                    throw $failure;
                }
            };
            try {
                $pool->close();
                self::fail('The first cleanup failure must propagate.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            self::assertSame([
                $first,
                $second,
            ], $factory->closed);
            self::assertTrue($second->closed);
            $factory->closer = null;
            $pool->close();
            self::assertTrue($first->closed);
            self::assertCount(3, $factory->closed);
            $pool->close();
            self::assertCount(3, $factory->closed);
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testCloseDuringConnectionEstablishmentCleansUpBeforeAnyBorrow(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $connection = new Connection();
            $gate = new Channel(1);
            $connection->connector = static function () use ($gate): void {
                self::assertTrue($gate->pop(1.0));
            };
            $factory->creator = static fn(): Connection => $connection;
            $pool = PoolFactory::create($factory, 1);
            $done = new Channel(1);
            $this->startCoroutine(function () use ($pool, $done): void {
                try {
                    $pool->acquire();
                    self::fail('An established connection must not escape a closed pool.');
                } catch (PoolClosedException) {
                    self::assertTrue($pool->isClosed());
                } finally {
                    $done->push(true);
                }
            });
            $pool->close();
            $gate->push(true);
            self::assertTrue($done->pop(1.0));
            self::assertTrue($connection->closed);
            self::assertSame([
                $connection,
            ], $factory->closed);
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testCloseDuringConnectionCreationCleansUpTheNewConnection(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $gate = new Channel(1);
            $factory->creator = static function () use ($gate): Connection {
                self::assertTrue($gate->pop(1.0));

                return new Connection();
            };
            $pool = PoolFactory::create($factory, 1);
            $done = new Channel(1);
            $this->startCoroutine(function () use ($pool, $done): void {
                try {
                    $pool->acquire();
                    self::fail('The newly created connection must not escape a closed pool.');
                } catch (PoolClosedException) {
                    self::assertTrue($pool->isClosed());
                } finally {
                    $done->push(true);
                }
            });
            $pool->close();
            $gate->push(true);
            self::assertTrue($done->pop(1.0));
            self::assertCount(1, $factory->created);
            self::assertSame($factory->created, $factory->closed);
            self::assertTrue($factory->created[0]->closed);
            self::assertSame(0, $factory->created[0]->connects);
        });
    }

    /**
     * The waiting coroutine closes the connection after the channel resumes its health check.
     *
     * @throws Throwable When a scenario or callback fails
     *
     * @noinspection PhpConditionAlreadyCheckedInspection
     */
    public function testCloseDuringHealthCheckDoesNotCloseAConnectionInUseByTheFactory(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1);
            $first = $pool->acquire();
            $pool->release($first);
            $gate = new Channel(1);
            $factory->healthCheck = static function (Connection $connection) use ($gate): bool {
                self::assertTrue($gate->pop(1.0));
                self::assertFalse($connection->closed);

                return true;
            };
            $done = new Channel(1);
            $this->startCoroutine(function () use ($pool, $done): void {
                try {
                    $pool->acquire();
                    self::fail('The checked connection must not escape a closed pool.');
                } catch (PoolClosedException) {
                    self::assertTrue($pool->isClosed());
                } finally {
                    $done->push(true);
                }
            });
            $pool->close();
            self::assertFalse($first->closed);
            $gate->push(true);
            self::assertTrue($done->pop(1.0));
            self::assertTrue($first->closed);
            self::assertSame([
                $first,
            ], $factory->closed);
        });
    }

    /** @throws Throwable When a scenario or callback fails */
    public function testCanceledWaiterDoesNotConsumeCapacity(): void
    {
        $this->runCoroutine(function (): void {
            $pool = PoolFactory::create(new ConnectionFactory(), 1);
            $connection = $pool->acquire();
            $done = new Channel(1);
            $id = $this->startCoroutine(function () use ($pool, $done): void {
                try {
                    $pool->acquire();
                    self::fail('A canceled waiter must not acquire a connection.');
                } catch (PoolException $exception) {
                    self::assertStringContainsString('canceled', $exception->getMessage());
                } finally {
                    $done->push(true);
                }
            });
            self::assertTrue(Coroutine::cancel($id));
            self::assertTrue($done->pop(1.0));
            $pool->release($connection);
            self::assertSame($connection, $pool->acquire(0.0));
            $pool->release($connection);
            $pool->close();
        });
    }
}
