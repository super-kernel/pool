<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Channel;

use InvalidArgumentException;
use SuperKernel\Pool\Contract\ChannelInterface;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

use function is_finite;

use const SWOOLE_CHANNEL_OK;
use const SWOOLE_CHANNEL_TIMEOUT;

/**
 * @template T
 *
 * @implements ChannelInterface<T>
 */
final class SwooleChannel implements ChannelInterface
{
    private ?Channel $channel = null;

    private bool $closed = false;

    /** @throws InvalidArgumentException When the channel capacity is not positive */
    public function __construct(private readonly int $capacity)
    {
        if (1 > $capacity) {
            throw new InvalidArgumentException('The channel capacity must be positive.');
        }
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function push(mixed $value): void
    {
        $this->assertOpen();
        $this->assertCoroutine();
        $channel = $this->channel();
        if ($channel->isFull()) {
            throw new PoolException('The connection channel is full.');
        }

        // No coroutine can fill the queue between this check and the non-blocking push.
        if (!$channel->push($value)) {
            $this->assertOpen();
            throw new PoolException('Unable to enqueue a connection channel value.', $channel->errCode);
        }
    }

    public function pop(float $timeout): mixed
    {
        if (!is_finite($timeout) || 0 > $timeout) {
            throw new InvalidArgumentException('The connection wait timeout must be finite and non-negative.');
        }
        $this->assertOpen();
        $this->assertCoroutine();
        $channel = $this->channel();
        if (0.0 === $timeout && $channel->isEmpty()) {
            throw new PoolTimeoutException('No connection channel value is available.');
        }

        $value = $channel->pop($timeout);
        $error = $channel->errCode;
        $this->assertOpen();
        if (SWOOLE_CHANNEL_TIMEOUT === $error) {
            throw new PoolTimeoutException('Timed out waiting for a connection.');
        }
        if (SWOOLE_CHANNEL_OK !== $error) {
            throw new PoolException('Connection acquisition was canceled.', $error);
        }

        return $value;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (null !== $this->channel) {
            $this->assertCoroutine();
        }

        $this->closed = true;
        $this->channel?->close();
        $this->channel = null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function channel(): Channel
    {
        return $this->channel ??= new Channel($this->capacity());
    }

    /** @throws PoolClosedException When the channel has closed */
    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PoolClosedException('The connection channel is closed.');
        }
    }

    /** @throws PoolException When no Swoole coroutine is active */
    private function assertCoroutine(): void
    {
        if (0 > Coroutine::getCid()) {
            throw new PoolException('Connection channel operations require an active Swoole coroutine.');
        }
    }
}
