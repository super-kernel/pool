<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SuperKernel\Pool\Capacity\ProcessCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\BackendAssertions;
use SuperKernel\Pool\Tests\Support\SharedWorker;
use Swoole\Atomic;
use Swoole\Process;
use Throwable;

use function usleep;

final class ProcessCapacityTest extends TestCase
{
    /** @throws Throwable When a process or shared backend fails */
    public function testNativeProcessesShareCapacityDuringCreationBorrowingAndCleanup(): void
    {
        $backend = new ProcessCapacity(2);
        $active = new Atomic();
        $total = new Atomic();
        $workers = [];
        for ($index = 0; 4 > $index; ++$index) {
            $worker = new Process(static function (Process $process) use ($backend, $active, $total): void {
                try {
                    SharedWorker::contend($backend, static function () use ($active, $total): int {
                        $total->add();

                        return $active->add();
                    }, static fn(): int => $active->sub());
                    $process->write('ok');
                    $process->exit();
                } catch (Throwable $exception) {
                    $process->write($exception->getMessage());
                    $process->exit(1);
                }
            });
            self::assertIsInt($worker->start());
            $workers[] = $worker;
        }
        foreach ($workers as $worker) {
            self::assertSame('ok', $worker->read());
            $status = Process::wait();
            self::assertIsArray($status);
            self::assertSame(0, $status['code']);
            self::assertSame(0, $status['signal']);
        }
        self::assertSame(32, $total->get());
        self::assertSame(0, $active->get());
        self::assertSame(2, $backend->available());
        $backend->close();
    }

    /** @throws Throwable When process ownership or local return fails */
    public function testChildCannotAcquireOrReturnAParentOwnedConnection(): void
    {
        $backend = new ProcessCapacity(1);
        $factory = new ConnectionFactory();
        $owner = new Pool(new PoolOption($factory, $backend, 1));
        $connection = $owner->acquire();
        self::assertInstanceOf(Connection::class, $connection);
        $worker = new Process(static function (Process $process) use ($backend, $owner, $connection): void {
            try {
                try {
                    $owner->acquire(0);
                    $process->exit(2);
                } catch (PoolException) {
                    // Inherited pools cannot acquire connections in a child process.
                }
                try {
                    $owner->release($connection);
                    $process->exit(3);
                } catch (PoolException) {
                    // Connections must be returned in the process that owns them.
                }
                $local = new Pool(new PoolOption(new ConnectionFactory(), $backend, 1));
                try {
                    $local->release($connection);
                    $process->exit(4);
                } catch (PoolException) {
                    // Another pool cannot return an object it never borrowed.
                }
                $process->write('ok');
                $process->exit();
            } catch (Throwable $exception) {
                $process->write($exception->getMessage());
                $process->exit(1);
            }
        });
        self::assertIsInt($worker->start());
        self::assertSame('ok', $worker->read());
        self::assertSame(0, Process::wait()['code']);
        self::assertSame(0, $backend->available());
        self::assertSame([], $factory->closed);
        $owner->release($connection);
        self::assertSame([
            $connection,
        ], $factory->closed);
        self::assertSame(1, $backend->available());
        $replacement = $owner->acquire(0);
        self::assertNotSame($connection, $replacement);
        $owner->discard($replacement);
        $owner->close();
    }

    /**
     * @throws Throwable When global shutdown or a waiting process fails
     */
    public function testGlobalCloseRejectsAProcessWaiterAndStillAcceptsReturn(): void
    {
        $backend = new ProcessCapacity(1);
        $owner = new Pool(new PoolOption(new ConnectionFactory(), $backend, 1));
        $connection = $owner->acquire();
        self::assertInstanceOf(Connection::class, $connection);
        $worker = new Process(static function (Process $process) use ($backend): void {
            try {
                $backend->reserve(2, 'waiter');
                $process->exit(2);
            } catch (PoolClosedException) {
                $process->exit();
            }
        });
        self::assertIsInt($worker->start());
        usleep(10000);
        $owner->close();
        self::assertSame(0, Process::wait()['code']);
        self::assertFalse($connection->closed);
        $owner->release($connection);
        self::assertTrue($connection->closed);
        self::assertSame(0, $backend->available());
    }
    /** @throws Throwable When shared reservation invariants fail */
    public function testReservationOwnershipTimeoutAndRelease(): void
    {
        BackendAssertions::verify(new ProcessCapacity(1));
    }

}
