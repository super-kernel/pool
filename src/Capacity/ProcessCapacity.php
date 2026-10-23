<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use InvalidArgumentException;
use SuperKernel\Pool\Exception\PoolException;
use Swoole\Atomic;
use Swoole\Exception;
use Swoole\Lock;
use Swoole\Table;

use const SWOOLE_MUTEX;

/** Create before forking; create each resource-owning Pool after forking. */
final class ProcessCapacity extends SharedCapacity
{
    private readonly Table $reservations;

    private readonly Lock $mutex;

    private readonly Atomic $closed;

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
        $this->reservations = new Table($limit * 2);
        $this->reservations->column('owner', Table::TYPE_STRING, 64);
        if (!$this->reservations->create()) {
            throw new PoolException('Unable to allocate shared reservation storage.');
        }
        $this->mutex = new Lock(SWOOLE_MUTEX);
        $this->closed = new Atomic();
    }

    public function capacity(): int
    {
        return $this->limit;
    }

    public function isClosed(): bool
    {
        return 1 === $this->closed->get();
    }

    /** @throws PoolException When shared reservation storage cannot be locked */
    protected function lock(): void
    {
        if (!$this->mutex->lock()) {
            throw new PoolException('Unable to lock shared reservation storage.');
        }
    }

    protected function unlock(): void
    {
        $this->mutex->unlock();
    }

    protected function read(string $reservation): ?string
    {
        $record = $this->reservations->get($reservation);

        return false === $record ? null : $record['owner'];
    }

    /** @throws PoolException When the reservation table is full */
    protected function write(string $reservation, string $owner): void
    {
        if (!$this->reservations->set($reservation, [
            'owner' => $owner,
        ])) {
            throw new PoolException('Unable to store the shared reservation.');
        }
    }

    protected function remove(string $reservation): void
    {
        $this->reservations->del($reservation);
    }

    protected function countReservations(): int
    {
        return $this->reservations->count();
    }

    protected function markClosed(): void
    {
        $this->closed->set(1);
    }
}
