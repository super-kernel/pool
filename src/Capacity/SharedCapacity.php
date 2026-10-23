<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use InvalidArgumentException;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Runtime\ExecutionContext;

use function hrtime;
use function is_finite;
use function min;
use function strlen;

/** @internal Shared capacity coordination; native client objects stay with their resource owner. */
abstract class SharedCapacity implements CapacityInterface
{
    public function reserve(float $timeout, string $owner): string
    {
        $this->assertContext();
        if (!is_finite($timeout) || 0 > $timeout || '' === $owner || 64 < strlen($owner)) {
            throw new InvalidArgumentException('A finite non-negative timeout and a 1-64 byte owner are required.');
        }
        $deadline = hrtime(true) / 1e9 + $timeout;
        $reservation = ExecutionContext::token();
        do {
            $this->lock();
            try {
                if ($this->isClosed()) {
                    throw new PoolClosedException('The connection backend is closed.');
                }
                if ($this->countReservations() < $this->capacity()) {
                    $this->write($reservation, $owner);

                    return $reservation;
                }
            } finally {
                $this->unlock();
            }
            $remaining = $deadline - hrtime(true) / 1e9;
            if (0 >= $remaining) {
                throw new PoolTimeoutException('No connection capacity became available before the timeout.');
            }
            ExecutionContext::pause(min(0.001, $remaining));
        } while (true);
    }

    public function release(string $reservation, string $owner): void
    {
        $this->lock();
        try {
            if ($this->read($reservation) !== $owner) {
                throw new PoolException('Only the reserving owner can release a capacity reservation.');
            }
            $this->remove($reservation);
        } finally {
            $this->unlock();
        }
    }

    public function available(): int
    {
        $this->lock();
        try {
            return $this->isClosed() ? 0 : $this->capacity() - $this->countReservations();
        } finally {
            $this->unlock();
        }
    }

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

    public function close(): void
    {
        $this->lock();
        try {
            $this->markClosed();
        } finally {
            $this->unlock();
        }
    }

    abstract protected function lock(): void;

    abstract protected function unlock(): void;

    abstract protected function read(string $reservation): ?string;

    abstract protected function write(string $reservation, string $owner): void;

    abstract protected function remove(string $reservation): void;

    abstract protected function countReservations(): int;

    abstract protected function markClosed(): void;
}
