<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use InvalidArgumentException;
use SuperKernel\Pool\Exception\PoolException;
use Swoole\Thread\Lock;
use Swoole\Thread\Map;

use function class_exists;
use function is_string;

/** Pass state() and mutex() as Swoole Thread arguments, then attach them in each thread. */
final class ThreadCapacity extends SharedCapacity
{
    private const string CAPACITY = '@capacity';

    private const string CLOSED = '@closed';

    /** @throws InvalidArgumentException When the shared state was not initialized by create() */
    public function __construct(private readonly Map $sharedState, private readonly Lock $sharedMutex)
    {
        if (1 > ($sharedState[self::CAPACITY] ?? 0)) {
            throw new InvalidArgumentException('Attach shared state initialized by ThreadCapacity::create().');
        }
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
        $state = new Map();
        $state[self::CAPACITY] = $capacity;
        $state[self::CLOSED] = false;

        return new self($state, new Lock());
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
        return $this->sharedState[self::CAPACITY];
    }

    public function isClosed(): bool
    {
        return $this->sharedState[self::CLOSED];
    }

    /** @throws PoolException When shared reservation storage cannot be locked */
    protected function lock(): void
    {
        if (!$this->sharedMutex->lock()) {
            throw new PoolException('Unable to lock shared reservation storage.');
        }
    }

    protected function unlock(): void
    {
        $this->sharedMutex->unlock();
    }

    protected function read(string $reservation): ?string
    {
        // Metadata cannot be used as a capacity reservation.
        $record = $this->sharedState[$reservation];

        return is_string($record) ? $record : null;
    }

    protected function write(string $reservation, string $owner): void
    {
        $this->sharedState->offsetSet($reservation, $owner);
    }

    protected function remove(string $reservation): void
    {
        $this->sharedState->offsetUnset($reservation);
    }

    protected function countReservations(): int
    {
        return $this->sharedState->count() - 2;
    }

    protected function markClosed(): void
    {
        $this->sharedState->offsetSet(self::CLOSED, true);
    }
}
