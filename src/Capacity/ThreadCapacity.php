<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use InvalidArgumentException;
use SuperKernel\Pool\Capacity\Reservation\SharedReservationCoordinator;
use SuperKernel\Pool\Capacity\Reservation\ThreadReservationStore;
use SuperKernel\Pool\Exception\PoolException;
use Swoole\Thread\Lock;
use Swoole\Thread\Map;

use function class_exists;

/** Pass state() and mutex() as Swoole Thread arguments, then attach them in each thread. */
final class ThreadCapacity extends SharedCapacity
{
    private readonly ThreadReservationStore $store;

    private readonly SharedReservationCoordinator $coordinator;

    /** @throws InvalidArgumentException When the shared state was not initialized by create() */
    public function __construct(private readonly Map $sharedState, private readonly Lock $sharedMutex)
    {
        if (ThreadReservationStore::isUninitialized($sharedState)) {
            throw new InvalidArgumentException('Attach shared state initialized by ThreadCapacity::create().');
        }
        $this->store = new ThreadReservationStore($sharedState, $sharedMutex);
        $this->coordinator = new SharedReservationCoordinator($this->store);
    }

    /**
     * @throws InvalidArgumentException When capacity is not positive
     * @throws PoolException            When PHP ZTS and Swoole thread support are unavailable
     */
    public static function create(int $capacity): self
    {
        if (1 > $capacity) {
            throw new InvalidArgumentException('Backend capacity must be positive.');
        }
        if (!class_exists(Map::class) || !class_exists(Lock::class)) {
            throw new PoolException('The thread backend requires PHP ZTS and Swoole thread support.');
        }

        return new self(ThreadReservationStore::initialState($capacity), new Lock());
    }

    public function state(): Map
    {
        return $this->sharedState;
    }

    public function mutex(): Lock
    {
        return $this->sharedMutex;
    }

    public function capacity(): int
    {
        return $this->store->capacity();
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
