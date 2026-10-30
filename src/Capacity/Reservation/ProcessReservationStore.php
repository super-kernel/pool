<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity\Reservation;

use SuperKernel\Pool\Exception\PoolException;
use Swoole\Atomic;
use Swoole\Exception;
use Swoole\Lock;
use Swoole\Table;

use const SWOOLE_MUTEX;

/**
 * Keeps process-shared reservations in a Swoole table guarded by a process mutex and an atomic shutdown flag.
 *
 * @internal
 */
final readonly class ProcessReservationStore
{
    public function __construct(
        private Table  $reservations,
        private Lock   $mutex,
        private Atomic $closed,
        private int    $capacity,
    )
    {
    }

    /**
     * Allocate the shared table, mutex and shutdown flag for a fixed capacity before forking.
     *
     * @throws Exception     When native shared-memory allocation fails
     * @throws PoolException When the reservation table cannot be created
     */
    public static function allocate(int $capacity): self
    {
        $reservations = new Table($capacity * 2);
        $reservations->column('owner', Table::TYPE_STRING, 64);
        if (!$reservations->create()) {
            throw new PoolException('Unable to allocate shared reservation storage.');
        }

        return new self($reservations, new Lock(SWOOLE_MUTEX), new Atomic(), $capacity);
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

    /** @return string|null The reserving owner, or null for an unknown reservation */
    public function read(string $reservation): ?string
    {
        $record = $this->reservations->get($reservation);

        return false === $record ? null : $record['owner'];
    }

    /** @throws PoolException When the reservation table is full */
    public function write(string $reservation, string $owner): void
    {
        if (!$this->reservations->set($reservation, [
            'owner' => $owner,
        ])) {
            throw new PoolException('Unable to store the shared reservation.');
        }
    }

    public function remove(string $reservation): void
    {
        $this->reservations->del($reservation);
    }

    public function countReservations(): int
    {
        return $this->reservations->count();
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function isClosed(): bool
    {
        return 1 === $this->closed->get();
    }

    public function markClosed(): void
    {
        $this->closed->set(1);
    }
}
