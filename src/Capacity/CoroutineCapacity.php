<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Capacity;

use InvalidArgumentException;
use SuperKernel\Pool\Channel\SwooleChannel;
use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Contract\ChannelInterface;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Runtime\ExecutionContext;

use function count;
use function is_finite;
use function strlen;

final class CoroutineCapacity implements CapacityInterface
{
    /** @var ChannelInterface<bool> */
    private readonly ChannelInterface $channel;

    /** @var array<string, string> */
    private array $reservations = [];

    private readonly string $context;

    private bool $initialized = false;

    private ?string $owner = null;

    /**
     * @param ChannelInterface<bool>|null $channel An exclusive, empty capacity-token channel
     *
     * @throws InvalidArgumentException When capacity is invalid or differs from the channel capacity
     */
    public function __construct(private readonly int $limit, ?ChannelInterface $channel = null)
    {
        if (1 > $limit || (null !== $channel && $channel->capacity() !== $limit)) {
            throw new InvalidArgumentException('The backend requires matching positive capacity.');
        }
        $this->channel = $channel ?? new SwooleChannel($limit);
        $this->context = ExecutionContext::id();
    }

    public function capacity(): int
    {
        return $this->limit;
    }

    public function reserve(float $timeout, string $owner): string
    {
        CoroutineContextGuard::assert($this->context);
        if (!is_finite($timeout) || 0 > $timeout || '' === $owner || 64 < strlen($owner)) {
            throw new InvalidArgumentException('A finite non-negative timeout and a 1-64 byte owner are required.');
        }
        if (null !== $this->owner && $this->owner !== $owner) {
            throw new PoolException('A coroutine backend belongs to one resource-owning pool.');
        }
        $this->owner = $owner;
        if (!$this->initialized && !$this->channel->isClosed()) {
            for ($index = 0; $index < $this->limit; ++$index) {
                $this->channel->push(true);
            }
            $this->initialized = true;
        }
        $this->channel->pop($timeout);
        try {
            $reservation = ExecutionContext::token();
            $this->reservations[$reservation] = $owner;

            return $reservation;
        } catch (PoolException $exception) {
            $this->channel->push(true);

            throw $exception;
        }
    }

    public function release(string $reservation, string $owner): void
    {
        CoroutineContextGuard::assert($this->context);
        if (($this->reservations[$reservation] ?? null) !== $owner) {
            throw new PoolException('Only the reserving owner can release a capacity reservation.');
        }
        unset($this->reservations[$reservation]);
        if (!$this->channel->isClosed()) {
            $this->channel->push(true);
        }
    }

    public function available(): int
    {
        return $this->channel->isClosed() ? 0 : $this->limit - count($this->reservations);
    }

    public function allowsIdleConnections(): bool
    {
        return true;
    }

    public function assertContext(): void
    {
        CoroutineContextGuard::assert($this->context);
    }

    public function contextId(): string
    {
        return ExecutionContext::id();
    }

    public function close(): void
    {
        if ($this->initialized && !$this->channel->isClosed()) {
            CoroutineContextGuard::assert($this->context);
        }
        $this->channel->close();
    }

    public function isClosed(): bool
    {
        return $this->channel->isClosed();
    }
}
