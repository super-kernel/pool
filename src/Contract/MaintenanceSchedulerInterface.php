<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

use SuperKernel\Pool\Exception\PoolException;

interface MaintenanceSchedulerInterface
{
    /** @throws PoolException When the scheduler cannot start or its pool is already closed */
    public function start(): void;

    /** Stop future passes; an in-flight pass finishes in its owning execution unit. */
    public function stop(): void;

    public function isRunning(): bool;
}
