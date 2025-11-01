<?php
declare(strict_types=1);

namespace SuperKernel\Pool\Pool;

use RuntimeException;
use SplQueue;
use SuperKernel\Pool\Contract\PoolConfigInterface;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Contract\ResourceFactoryInterface;
use SuperKernel\Pool\Contract\ResourceValidatorInterface;
use SuperKernel\Pool\PoolConfig;
use Swoole\Timer;
use Throwable;

final class TimerPool implements PoolInterface
{
	private ?SplQueue $queue;

	protected int $num = 0;

	private readonly PoolConfigInterface $poolConfig;

	protected ?int $timerId = null;

	public function __construct(
		private readonly ResourceFactoryInterface   $factory,
		private readonly ResourceValidatorInterface $validator,
		?PoolConfigInterface                        $poolConfig = null,
	)
	{
		$this->poolConfig = $poolConfig ?? new PoolConfig();
		$this->queue      = new SplQueue();

		for ($i = 0; $i < $this->poolConfig->getMinConnections(); ++$i) {
			$this->make();
		}

		$this->timerId = Timer::tick($poolConfig->getHeartbeat() * 1000, function () {

			while (!$this->queue->isEmpty()) {
				$resource = $this->queue->dequeue();

				if ($this->validator->validate($resource)) {
					$this->queue->enqueue($resource);
				} else {
					$this->num -= 1;

					$this->make();
				}
			}
		});
	}

	public function get(): object
	{
		if (null === $this->queue) {
			throw new RuntimeException('Pool has been closed');
		}

		if ($this->queue->isEmpty() && $this->num < $this->poolConfig->getMaxConnections()) {
			$this->make();
		}

		$start = microtime(true);
		while ($this->queue->isEmpty()) {
			usleep(1000);
			if ((microtime(true) - $start) > $this->poolConfig->getWaitTimeout()) {
				throw new RuntimeException('timeout waiting for resource');
			}
		}

		return $this->queue->dequeue();
	}

	public function release(object $resource): void
	{
		if (null === $this->queue) {
			return;
		}

		if ($this->validator->validate($resource)) {
			$this->queue->enqueue($resource);
			return;
		}

		$this->num -= 1;
		$this->make();
	}

	public function close(): void
	{
		if (null === $this->queue) {
			return;
		}

		Timer::clear($this->timerId);

		$this->queue = null;
		$this->num   = 0;
	}

	private function make(): void
	{
		$this->num++;

		try {
			$this->queue->enqueue($this->factory->create());
		}
		catch (Throwable $throwable) {
			$this->num--;
			throw new RuntimeException($throwable->getMessage(), $throwable->getCode(), $throwable);
		}
	}
}