<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Config;

use SuperKernel\Pool\Constants\HealthCheckMode;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Contract\ConnectionFactoryInterface;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Pool\Contract\FrequencyInterface;
use SuperKernel\Pool\Frequency\ConstantFrequency;

/**
 * Fixes limits and collaborator references for one pool without configuration discovery or connection I/O.
 * Pool validates limits before use. Mutable frequency policies belong to one pool.
 * Driver-specific connection settings remain with the caller's connection factory.
 *
 * @template T of ConnectionInterface
 */
final readonly class PoolOption
{
    /** @param ConnectionFactoryInterface<T> $factory */
    public function __construct(
        private ConnectionFactoryInterface $factory,
        private CapacityInterface          $capacity,
        private int                        $maxConnections,
        private float                      $waitTimeout = 1.0,
        private HealthCheckMode            $healthChecks = HealthCheckMode::OnBorrowAndReturn,
        private FrequencyInterface         $frequency = new ConstantFrequency(),
    )
    {
    }

    /** @return int Fixed positive limit for idle, borrowed, creating, checking and closing connections */
    public function maxConnections(): int
    {
        return $this->maxConnections;
    }

    /** @return float Finite, non-negative capacity wait timeout in seconds; zero means no waiting */
    public function waitTimeout(): float
    {
        return $this->waitTimeout;
    }

    /** @return ConnectionFactoryInterface<T> Factory used only after capacity is reserved */
    public function factory(): ConnectionFactoryInterface
    {
        return $this->factory;
    }

    /** @return CapacityInterface Coordinator whose fixed capacity matches maxConnections() */
    public function capacity(): CapacityInterface
    {
        return $this->capacity;
    }

    public function healthChecks(): HealthCheckMode
    {
        return $this->healthChecks;
    }

    public function frequency(): FrequencyInterface
    {
        return $this->frequency;
    }
}
