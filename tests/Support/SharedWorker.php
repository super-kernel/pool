<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Support;

use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;
use Throwable;

use function usleep;

final class SharedWorker
{
    /**
     * @param Closure(): int $opened
     * @param Closure(): int $closed
     *
     * @throws Throwable When shared capacity, owner cleanup, or resource creation fails
     */
    public static function contend(CapacityInterface $backend, Closure $opened, Closure $closed): void
    {
        $factory = new ConnectionFactory();
        $factory->creator = static function () use ($opened, $backend): Connection {
            if ($opened() > $backend->capacity()) {
                throw new RuntimeException('Concurrent live connections exceeded shared capacity.');
            }
            usleep(1000);

            return new Connection();
        };
        $factory->closer = static function () use ($closed): void {
            usleep(1000);
            if (0 > $closed()) {
                throw new RuntimeException('Resource cleanup was duplicated.');
            }
        };
        $pool = new Pool(new PoolOption($factory, $backend, $backend->capacity(), 5));
        for ($index = 0; 8 > $index; ++$index) {
            $connection = $pool->acquire();
            Assert::assertInstanceOf(Connection::class, $connection);
            usleep(1000);
            $pool->release($connection);
            if (!$connection->closed) {
                throw new RuntimeException('Shared returns must clean up in their resource owner.');
            }
        }
    }
}
