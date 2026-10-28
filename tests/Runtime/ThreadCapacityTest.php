<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Runtime;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SuperKernel\Pool\Capacity\ThreadCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\BackendAssertions;
use SuperKernel\Pool\Tests\Support\ThreadScript;
use Swoole\Thread;
use Swoole\Thread\Map;
use Throwable;

use function class_exists;
use function unlink;
use function usleep;

#[Group('thread')]
final class ThreadCapacityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Thread::class)) {
            self::markTestSkipped('Requires PHP ZTS with Swoole thread support; run the ZTS runtime suite.');
        }
    }

    /** @throws Throwable When native threads or shared capacity fail */
    public function testNativeThreadsShareCapacityWithoutTransferringClients(): void
    {
        $backend = ThreadCapacity::create(2);
        $metrics = new Map([
            'active' => 0,
            'total'  => 0,
        ]);
        $script = ThreadScript::create();
        try {
            $threads = [];
            for ($index = 0; 4 > $index; ++$index) {
                $threads[] = new Thread($script, $backend->state(), $backend->mutex(), $metrics, 'contend');
            }
            foreach ($threads as $thread) {
                self::assertTrue($thread->join());
                self::assertSame(0, $thread->getExitStatus());
            }
            self::assertNull($metrics['error']);
            self::assertSame(32, $metrics['total']);
            self::assertSame(0, $metrics['active']);
            self::assertSame(2, $backend->available());
            $backend->close();
        } finally {
            unlink($script);
        }
    }

    /**
     * @throws Throwable When shutdown fails to notify a thread waiter
     */
    public function testGlobalCloseNotifiesAThreadWaiterAndDefersBorrowerCleanup(): void
    {
        $backend = ThreadCapacity::create(1);
        $owner = new Pool(new PoolOption(new ConnectionFactory(), $backend, 1));
        $connection = $owner->acquire();
        self::assertInstanceOf(Connection::class, $connection);
        $metrics = new Map();
        $script = ThreadScript::create();
        try {
            $thread = new Thread($script, $backend->state(), $backend->mutex(), $metrics, 'close');
            usleep(10000);
            $owner->close();
            self::assertTrue($thread->join());
            self::assertSame(0, $thread->getExitStatus());
            self::assertNull($metrics['error']);
            self::assertTrue($metrics['closed']);
            self::assertFalse($connection->closed);
            $owner->release($connection);
            self::assertTrue($connection->closed);
        } finally {
            unlink($script);
        }
    }
    /** @throws Throwable When shared reservation invariants fail */
    public function testReservationOwnershipTimeoutAndRelease(): void
    {
        BackendAssertions::verify(ThreadCapacity::create(1));
    }

}
