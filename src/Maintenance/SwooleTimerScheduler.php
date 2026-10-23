<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Maintenance;

use Closure;
use InvalidArgumentException;
use SuperKernel\Pool\Contract\MaintenanceSchedulerInterface;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Exception\PoolException;
use Swoole\Coroutine;
use Swoole\Timer;
use Throwable;

final class SwooleTimerScheduler implements MaintenanceSchedulerInterface
{
    private ?int $timer = null;

    private bool $runningPass = false;

    /**
     * @param Closure(Throwable): void $onError Receives maintenance failures; must not throw in the timer callback
     *
     * @throws InvalidArgumentException When the interval is not positive or the idle minimum is negative
     */
    public function __construct(
        private readonly PoolInterface $maintenance,
        private readonly int           $intervalMilliseconds,
        private readonly Closure       $onError,
        private readonly int           $minimumIdle = 0,
    )
    {
        if (1 > $intervalMilliseconds || 0 > $minimumIdle) {
            throw new InvalidArgumentException('A positive interval and a non-negative idle minimum are required.');
        }
    }

    /** @throws PoolException When maintenance is closed or the timer cannot be started */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }
        if ($this->maintenance->isClosed()) {
            throw new PoolException('Cannot schedule maintenance on a closed pool.');
        }
        $timer = Timer::tick($this->intervalMilliseconds, function (): void {
            if ($this->maintenance->isClosed()) {
                $this->stop();

                return;
            }
            if ($this->runningPass) {
                return;
            }
            $this->runningPass = true;
            if (0 <= Coroutine::getCid()) {
                $this->runPass();
            } elseif (false === Coroutine::create($this->runPass(...))) {
                $this->runningPass = false;
                ($this->onError)(new PoolException('Cannot start the maintenance coroutine.'));
            }
        });
        if (false === $timer) {
            throw new PoolException('Cannot start the maintenance timer.');
        }
        $this->timer = $timer;
    }

    public function stop(): void
    {
        if (null !== $this->timer) {
            Timer::clear($this->timer);
            $this->timer = null;
        }
    }

    public function isRunning(): bool
    {
        return null !== $this->timer && Timer::exists($this->timer);
    }

    private function runPass(): void
    {
        try {
            $this->maintenance->maintain($this->minimumIdle);
        } catch (Throwable $exception) {
            ($this->onError)($exception);
        } finally {
            $this->runningPass = false;
        }
    }
}
