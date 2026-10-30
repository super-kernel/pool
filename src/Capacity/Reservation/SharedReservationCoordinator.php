<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity\Reservation;

use InvalidArgumentException;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Runtime\ExecutionContext;

use function hrtime;
use function is_finite;
use function min;
use function strlen;

/**
 * Reserves, releases and closes shared capacity under the native mutex of a process or thread reservation store.
 *
 * @internal
 */
final readonly class SharedReservationCoordinator
{
    public function __construct(private ProcessReservationStore|ThreadReservationStore $store)
    {
    }

    /**
     * Poll for a free slot until a monotonic deadline, pausing at most 1 ms between attempts.
     *
     * @throws InvalidArgumentException When the timeout is negative or non-finite, or the owner is not 1-64 bytes
     * @throws PoolClosedException      When the shared capacity has closed
     * @throws PoolTimeoutException     When no slot becomes available before the timeout
     * @throws PoolException            When token generation, storage locking or writing fails, or a waiting
     *                                  coroutine is canceled
     */
    public function reserve(float $timeout, string $owner): string
    {
        if (!is_finite($timeout) || 0 > $timeout || '' === $owner || 64 < strlen($owner)) {
            throw new InvalidArgumentException('A finite non-negative timeout and a 1-64 byte owner are required.');
        }
        $deadline = hrtime(true) / 1e9 + $timeout;
        $reservation = ExecutionContext::token();
        do {
            $this->store->lock();
            try {
                if ($this->store->isClosed()) {
                    throw new PoolClosedException('The connection backend is closed.');
                }
                if ($this->store->countReservations() < $this->store->capacity()) {
                    $this->store->write($reservation, $owner);

                    return $reservation;
                }
            } finally {
                $this->store->unlock();
            }
            $remaining = $deadline - hrtime(true) / 1e9;
            if (0 >= $remaining) {
                throw new PoolTimeoutException('No connection capacity became available before the timeout.');
            }
            ExecutionContext::pause(min(0.001, $remaining));
        } while (true);
    }

    /** @throws PoolException When the storage cannot be locked or the reservation does not belong to the owner */
    public function release(string $reservation, string $owner): void
    {
        $this->store->lock();
        try {
            if ($this->store->read($reservation) !== $owner) {
                throw new PoolException('Only the reserving owner can release a capacity reservation.');
            }
            $this->store->remove($reservation);
        } finally {
            $this->store->unlock();
        }
    }

    /**
     * @return int Reservable slots; zero after shutdown
     *
     * @throws PoolException When the storage cannot be locked
     */
    public function available(): int
    {
        $this->store->lock();
        try {
            return $this->store->isClosed() ? 0 : $this->store->capacity() - $this->store->countReservations();
        } finally {
            $this->store->unlock();
        }
    }

    /** @throws PoolException When the storage cannot be locked */
    public function close(): void
    {
        $this->store->lock();
        try {
            $this->store->markClosed();
        } finally {
            $this->store->unlock();
        }
    }
}
