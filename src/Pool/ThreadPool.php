<?php
declare(strict_types=1);

namespace SuperKernel\Pool\Pool;

use RuntimeException;
use SuperKernel\Pool\Contract\PoolConfigInterface;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Contract\ResourceFactoryInterface;
use SuperKernel\Pool\Contract\ResourceValidatorInterface;
use SuperKernel\Pool\PoolConfig;
use SuperKernel\Pool\ThreadSafeQueue;
use Throwable;

final class ThreadPool implements PoolInterface
{
	private ?ThreadSafeQueue $queue;

	protected int $num = 0;

	private readonly PoolConfigInterface $poolConfig;

	public function __construct(
		private readonly ResourceFactoryInterface   $factory,
		private readonly ResourceValidatorInterface $validator,
		?PoolConfigInterface                        $poolConfig = null,
	)
	{
		$this->poolConfig = $poolConfig ?? new PoolConfig();
		$this->queue      = new ThreadSafeQueue();

		for ($i = 0; $i < $this->poolConfig->getMinConnections(); ++$i) {
			$this->make();
		}
	}

	public function get(): object
	{
		if (null === $this->queue) {
			throw new RuntimeException('Pool has been closed');
		}

		if ($this->queue->isEmpty() && $this->num < $this->poolConfig->getMaxConnections()) {
			$this->make();
		}

		$resource = $this->queue->pop($this->poolConfig->getWaitTimeout());

		if (!$resource) {
			throw new RuntimeException('timeout waiting for resource');
		}

		return $resource;
	}

	public function release(object $resource): void
	{
		if (null === $this->queue) {
			return;
		}

		if ($this->validator->validate($resource)) {
			$this->queue->push($resource);
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

		$this->queue = null;
		$this->num   = 0;
	}

	private function make(): void
	{
		$this->num++;

		try {
			$this->queue->push($this->factory->create());
		}
		catch (Throwable $throwable) {
			$this->num--;
			throw new RuntimeException($throwable->getMessage(), $throwable->getCode(), $throwable);
		}
	}
}