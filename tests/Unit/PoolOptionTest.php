<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Exception\InvalidPoolConfigurationException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use SuperKernel\Pool\Tests\Support\PoolFactory;
use Throwable;

use const INF;
use const NAN;

final class PoolOptionTest extends TestCase
{
    /** @return iterable<string, array{int, float}> */
    public static function invalidOptions(): iterable
    {
        yield 'zero capacity' => [
            0,
            1.0,
        ];
        yield 'negative capacity' => [
            -1,
            1.0,
        ];
        yield 'negative timeout' => [
            1,
            -1.0,
        ];
        yield 'infinite timeout' => [
            1,
            INF,
        ];
        yield 'negative infinite timeout' => [
            1,
            -INF,
        ];
        yield 'not a number' => [
            1,
            NAN,
        ];
    }

    /** @throws Throwable When invalid pool limits are rejected */
    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidLimitsWithoutAConfigurationProvider(int $capacity, float $timeout): void
    {
        $factory = new ConnectionFactory();
        $channel = new CoroutineCapacity(1);
        $option = new PoolOption($factory, $channel, $capacity, $timeout);
        try {
            new Pool($option);
            self::fail('Invalid limits must be rejected before any connection is created.');
        } catch (InvalidPoolConfigurationException) {
            self::assertSame([], $factory->created);
            self::assertFalse($channel->isClosed());
        }
    }

    public function testRejectsAMismatchedChannelCapacity(): void
    {
        $channel = new CoroutineCapacity(1);
        $option = new PoolOption(new ConnectionFactory(), $channel, 2);
        $this->expectException(InvalidPoolConfigurationException::class);
        new Pool($option);
    }

    /** @throws Throwable When an unexpected pool operation fails */
    public function testConstructionAndUnusedShutdownDoNotRequireACoroutine(): void
    {
        $factory = new ConnectionFactory();
        $pool = PoolFactory::create($factory);
        self::assertFalse($pool->isClosed());
        self::assertSame([], $factory->created);
        $pool->close();
        $pool->close();
        self::assertTrue($pool->isClosed());
        self::assertSame([], $factory->closed);
    }

    /** @throws Throwable When acquisition fails */
    public function testAcquisitionRequiresACoroutine(): void
    {
        $pool = PoolFactory::create(new ConnectionFactory());
        $this->expectException(PoolException::class);
        $this->expectExceptionMessageIs('Connection pool operations require an active Swoole coroutine.');
        $pool->acquire();
    }
}
