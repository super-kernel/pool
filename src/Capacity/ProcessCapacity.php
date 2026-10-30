<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use InvalidArgumentException;
use SuperKernel\Pool\Capacity\Reservation\ProcessReservationStore;
use SuperKernel\Pool\Capacity\Reservation\SharedReservationCoordinator;
use SuperKernel\Pool\Exception\PoolException;
use Swoole\Exception;

/** Create before forking; create each resource-owning Pool after forking. */
final class ProcessCapacity extends SharedCapacity
{
    private readonly ProcessReservationStore $store;

    private readonly SharedReservationCoordinator $coordinator;

    /**
     * @throws InvalidArgumentException When capacity is not positive
     * @throws Exception                When native shared-memory allocation fails
     * @throws PoolException            When shared memory allocation fails
     */
    public function __construct(private readonly int $limit)
    {
        if (1 > $limit) {
            throw new InvalidArgumentException('Backend capacity must be positive.');
        }
        $this->store = ProcessReservationStore::allocate($limit);
        $this->coordinator = new SharedReservationCoordinator($this->store);
    }

    public function capacity(): int
    {
        return $this->limit;
    }

    public function isClosed(): bool
    {
        return $this->store->isClosed();
    }

    public function reserve(float $timeout, string $owner): string
    {
        return $this->coordinator->reserve($timeout, $owner);
    }

    public function release(string $reservation, string $owner): void
    {
        $this->coordinator->release($reservation, $owner);
    }

    public function available(): int
    {
        return $this->coordinator->available();
    }

    public function close(): void
    {
        $this->coordinator->close();
    }
}
