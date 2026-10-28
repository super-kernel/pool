<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Integration;

use RuntimeException;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Capacity\ProcessCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Constants\HealthCheckMode;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Maintenance\SwooleTimerScheduler;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\CoroutineTestCase;
use SuperKernel\Pool\Tests\Support\PoolFactory;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function count;
use function max;

final class MaintenanceTest extends CoroutineTestCase
{
    /** @throws Throwable When returning a connection or coordinating cleanup fails */
    public function testRepeatedReturnsAreRejectedWhileCleanupIsStillRunning(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $backend = new CoroutineCapacity(1);
            $pool = new Pool(new PoolOption($factory, $backend, 1));
            $connection = $pool->acquire();
            $started = new Channel(1);
            $finish = new Channel(1);
            $done = new Channel(1);
            $factory->closer = static function () use ($started, $finish): void {
                $started->push(true);
                self::assertTrue($finish->pop(1));
            };
            $this->startCoroutine(static function () use ($pool, $connection, $done): void {
                $pool->discard($connection);
                $done->push(true);
            });
            self::assertTrue($started->pop(1));
            $pool->maintain();
            self::assertCount(1, $factory->closed);
            self::assertCount(1, $factory->created);
            foreach ([
                'release',
                'discard',
            ] as $method) {
                try {
                    $pool->{$method}($connection);
                    self::fail('A connection being cleaned up must reject another return.');
                } catch (PoolException) {
                    self::assertSame(0, $backend->available());
                    self::assertCount(1, $factory->closed);
                }
            }
            $finish->push(true);
            self::assertTrue($done->pop(1));
            self::assertSame(1, $backend->available());
            $factory->closer = null;
            $pool->close();
        });
    }

    /** @throws Throwable When a configured health trigger fails */
    public function testHealthModesAndExplicitMaintenanceDoNotCheckBorrowedConnections(): void
    {
        $this->runCoroutine(static function (): void {
            foreach (HealthCheckMode::cases() as $mode) {
                $factory = new ConnectionFactory();
                $checks = 0;
                $factory->healthCheck = static function () use (&$checks): bool {
                    ++$checks;

                    return true;
                };
                $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(1), 1, healthChecks: $mode));
                $first = $pool->acquire();
                $pool->maintain(1);
                self::assertSame(0, $checks);
                self::assertCount(1, $factory->created);
                $pool->release($first);
                self::assertSame($mode->onReturn() ? 1 : 0, $checks);
                $second = $pool->acquire();
                $pool->release($second);
                $expected = ($mode->onReturn() ? 2 : 0) + ($mode->onBorrow() ? 1 : 0);
                self::assertSame($expected, $checks);
                $pool->maintain();
                self::assertSame($expected + 1, $checks);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When replacing an unhealthy idle connection fails */
    public function testManualMaintenanceDiscardsUnhealthyIdleConnectionsAndReplenishes(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $pool = new Pool(
                new PoolOption($factory, new CoroutineCapacity(2), 2, healthChecks: HealthCheckMode::Manual),
            );
            $pool->maintain(2);
            self::assertCount(2, $factory->created);
            $factory->created[0]->healthy = false;
            $pool->maintain(2);
            self::assertTrue($factory->created[0]->closed);
            self::assertCount(3, $factory->created);
            self::assertCount(1, $factory->closed);
            $pool->close();
        });
    }

    /** @throws Throwable When concurrent creation or maintenance fails */
    public function testReplenishmentCountsInFlightCreationAndSkipsOverlappingPasses(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $creating = 0;
            $peak = 0;
            $factory->creator = static function () use (&$creating, &$peak): Connection {
                $peak = max($peak, ++$creating);
                Coroutine::sleep(0.005);
                --$creating;

                return new Connection();
            };
            $pool = new Pool(new PoolOption($factory, new CoroutineCapacity(2), 2));
            $done = new Channel(2);
            $this->startCoroutine(static function () use ($pool, $done): void {
                $pool->maintain(2);
                $done->push(true);
            });
            $pool->maintain(2);
            $this->startCoroutine(static function () use ($pool, $done): void {
                $connection = $pool->acquire();
                Coroutine::sleep(0.01);
                $pool->release($connection);
                $done->push(true);
            });
            self::assertTrue($done->pop(1));
            self::assertTrue($done->pop(1));
            self::assertSame(2, $peak);
            self::assertCount(2, $factory->created);
            $pool->close();
        });
    }

    /** @throws Throwable When return health validation fails unexpectedly */
    public function testReturnHealthFailurePreservesCauseAndRetainsCapacityUntilCleanupSucceeds(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $backend = new CoroutineCapacity(1);
            $pool = new Pool(new PoolOption($factory, $backend, 1, healthChecks: HealthCheckMode::OnReturn));
            $connection = $pool->acquire();
            $failure = new RuntimeException('health failed');
            $factory->healthCheck = static fn(): never => throw $failure;
            $factory->closer = static fn(): never => throw new RuntimeException('cleanup failed');
            try {
                $pool->release($connection);
                self::fail('The health failure must propagate.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            self::assertSame(0, $backend->available());
            $factory->healthCheck = null;
            $factory->closer = null;
            $pool->maintain();
            self::assertSame(1, $backend->available());
            $next = $pool->acquire(0);
            $pool->discard($next);
            $pool->close();
        });
    }

    /**
     * Resource cleanup changes the property through callbacks that PhpStorm does not track.
     *
     * @throws Throwable When shutdown races with a maintenance health check
     *
     * @noinspection PhpConditionAlreadyCheckedInspection
     */
    public function testCloseDuringMaintenanceDefersCleanupUntilTheHealthCheckFinishes(): void
    {
        $this->runCoroutine(function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1);
            $pool->maintain(1);
            $connection = $factory->created[0];
            $gate = new Channel(1);
            $done = new Channel(1);
            $factory->healthCheck = static function () use ($gate, $connection): bool {
                self::assertTrue($gate->pop(1));
                self::assertFalse($connection->closed);

                return true;
            };
            $this->startCoroutine(static function () use ($pool, $done): void {
                $pool->maintain(1);
                $done->push(true);
            });
            $pool->close();
            self::assertFalse($connection->closed);
            $gate->push(true);
            self::assertTrue($done->pop(1));
            self::assertTrue($connection->closed);
            self::assertCount(1, $factory->closed);
        });
    }

    /** @throws Throwable When timer scheduling or error delivery fails */
    public function testTimerReportsFailuresSkipsOverlapsAndStopsAfterShutdown(): void
    {
        $this->runCoroutine(static function (): void {
            $factory = new ConnectionFactory();
            $pool = PoolFactory::create($factory, 1);
            $errors = [];
            $checks = 0;
            $active = 0;
            $peak = 0;
            $factory->healthCheck = static function () use (&$checks, &$active, &$peak): bool {
                $peak = max($peak, ++$active);
                ++$checks;
                Coroutine::sleep(0.005);
                --$active;
                if (1 === $checks) {
                    throw new RuntimeException('scheduled health failure');
                }

                return true;
            };
            $scheduler = new SwooleTimerScheduler($pool, 1, static function (Throwable $error) use (&$errors): void {
                $errors[] = $error;
            }, 1);
            self::assertFalse($scheduler->isRunning());
            self::assertSame([], $factory->created);
            try {
                $scheduler->start();
                $scheduler->start();
                Coroutine::sleep(0.035);
                self::assertTrue($scheduler->isRunning());
                self::assertSame(1, $peak);
                self::assertGreaterThan(1, $checks);
                self::assertCount(1, $errors);
                self::assertSame('scheduled health failure', $errors[0]->getMessage());
                $pool->close();
                Coroutine::sleep(0.01);
                self::assertFalse($scheduler->isRunning());
                self::assertSame(count($factory->created), count($factory->closed));
            } finally {
                $scheduler->stop();
                $pool->close();
            }
        });
    }

    /** @throws Throwable When shared capacity validation fails */
    public function testSharedMaintenanceRejectsAnUnsupportedIdleMinimum(): void
    {
        $backend = new ProcessCapacity(1);
        $pool = new Pool(new PoolOption(new ConnectionFactory(), $backend, 1));
        try {
            $pool->maintain(1);
            self::fail('Shared clients cannot be retained as transferable idle resources.');
        } catch (PoolException) {
            self::assertSame(1, $backend->available());
        } finally {
            $pool->close();
        }
    }
    /** @throws Throwable When shared waiting fails to honor coroutine cancellation */
    public function testSharedCapacityCancellationDoesNotConsumeCapacity(): void
    {
        $this->runCoroutine(function (): void {
            $backend = new ProcessCapacity(1);
            $reservation = $backend->reserve(0, 'owner');
            $done = new Channel(1);
            $waiter = $this->startCoroutine(static function () use ($backend, $done): void {
                try {
                    $backend->reserve(1, 'waiter');
                    self::fail('A canceled wait must not acquire capacity.');
                } catch (PoolException $exception) {
                    $done->push($exception->getMessage());
                }
            });
            self::assertTrue(Coroutine::cancel($waiter));
            self::assertSame('The capacity wait was canceled.', $done->pop(1));
            self::assertSame(0, $backend->available());
            $backend->release($reservation, 'owner');
            self::assertSame(1, $backend->available());
            $backend->close();
        });
    }

}
