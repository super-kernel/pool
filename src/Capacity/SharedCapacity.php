<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Runtime\ExecutionContext;

/** @internal Shared capacity coordination; native client objects stay with their resource owner. */
abstract class SharedCapacity implements CapacityInterface
{
    public function allowsIdleConnections(): bool
    {
        return false;
    }

    public function assertContext(): void
    {
    }

    public function contextId(): string
    {
        return ExecutionContext::id();
    }
}
