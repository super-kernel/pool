<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Support;

use InvalidArgumentException;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Exception\InvalidPoolConfigurationException;
use SuperKernel\Pool\Pool;
use SuperKernel\Pool\Tests\Fixture\Connection;
use SuperKernel\Pool\Tests\Fixture\ConnectionFactory;

final class PoolFactory
{
    /**
     * @return Pool<Connection>
     *
     * @throws InvalidArgumentException          When the channel capacity is not positive
     * @throws InvalidPoolConfigurationException When pool limits are invalid or the channel capacity differs
     */
    public static function create(
        ConnectionFactory $factory,
        int               $maxConnections = 10,
        float             $waitTimeout = 1.0,
    ): Pool
    {
        return new Pool(new PoolOption(
            $factory,
            new CoroutineCapacity($maxConnections),
            $maxConnections,
            $waitTimeout,
        ));
    }
}
