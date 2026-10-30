<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity\Reservation;

use SuperKernel\Pool\Exception\PoolException;
use Swoole\Thread\Lock;
use Swoole\Thread\Map;

use function is_string;

/**
 * Keeps thread-shared reservations, capacity and shutdown state in a Swoole thread map guarded by a thread lock.
 *
 * @internal
 */
final readonly class ThreadReservationStore
{
    /** Shared-state key of the fixed capacity. */
    private const string CAPACITY = '@capacity';

    /** Shared-state key of the shutdown flag. */
    private const string CLOSED = '@closed';

    public function __construct(private Map $state, private Lock $mutex)
    {
    }

    /** @return Map New shared state holding the fixed capacity and an open shutdown flag, without reservations */
    public static function initialState(int $capacity): Map
    {
        $state = new Map();
        $state[self::CAPACITY] = $capacity;
        $state[self::CLOSED] = false;

        return $state;
    }

    /** @return bool Whether the shared state lacks the positive capacity that initialState() stores */
    public static function isUninitialized(Map $state): bool
    {
        return 1 > ($state[self::CAPACITY] ?? 0);
    }

    /** @throws PoolException When shared reservation storage cannot be locked */
    public function lock(): void
    {
        if (!$this->mutex->lock()) {
            throw new PoolException('Unable to lock shared reservation storage.');
        }
    }

    public function unlock(): void
    {
        $this->mutex->unlock();
    }

    /** @return string|null The reserving owner, or null for an unknown reservation or a metadata key */
    public function read(string $reservation): ?string
    {
        // Metadata cannot be used as a capacity reservation.
        $record = $this->state[$reservation];

        return is_string($record) ? $record : null;
    }

    public function write(string $reservation, string $owner): void
    {
        $this->state->offsetSet($reservation, $owner);
    }

    public function remove(string $reservation): void
    {
        $this->state->offsetUnset($reservation);
    }

    public function countReservations(): int
    {
        return $this->state->count() - 2;
    }

    public function capacity(): int
    {
        return $this->state[self::CAPACITY];
    }

    public function isClosed(): bool
    {
        return $this->state[self::CLOSED];
    }

    public function markClosed(): void
    {
        $this->state->offsetSet(self::CLOSED, true);
    }
}
