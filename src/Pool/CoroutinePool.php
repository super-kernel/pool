<?php
declare(strict_types=1);

namespace SuperKernel\Pool\Pool;

use RuntimeException;
use SuperKernel\Pool\Contract\PoolConfigInterface;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Contract\ResourceFactoryInterface;
use SuperKernel\Pool\Contract\ResourceValidatorInterface;
use SuperKernel\Pool\PoolConfig;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

final class CoroutinePool implements PoolInterface
{
	private ?Channel $channel;

	protected int $num = 0;

	private readonly PoolConfigInterface $poolConfig;

	public function __construct(
		private readonly ResourceFactoryInterface   $factory,
		private readonly ResourceValidatorInterface $validator,
		?PoolConfigInterface                        $poolConfig = null,
	)
	{
		$this->poolConfig = $poolConfig ?? new PoolConfig();
		$this->channel    = new Channel($this->poolConfig->getMaxConnections());

		for ($i = 0; $i < $this->poolConfig->getMinConnections(); ++$i) {
			$this->make();
		}

		Coroutine::create(function () {
			while (true) {
				Coroutine::sleep($this->poolConfig->getHeartbeat());

				try {
					$resource = $this->get();

					$this->release($resource);
				}
				catch (Throwable) {
				}
			}
		});
	}

	public function get(): object
	{
		if (null === $this->channel) {
			throw new RuntimeException('Pool has been closed');
		}

		if ($this->channel->isEmpty() && $this->num < $this->poolConfig->getMaxConnections()) {
			$this->make();
		}

		$resource = $this->channel->pop($this->poolConfig->getWaitTimeout());

		if (!$resource) {
			throw new RuntimeException('timeout waiting for resource');
		}

		return $resource;
	}

	public function release(object $resource): void
	{
		if (null === $this->channel) {
			return;
		}

		if ($this->validator->validate($resource)) {
			$this->channel->push($resource);
			return;
		}

		$this->num -= 1;
		$this->make();
	}

	public function close(): void
	{
		if (null === $this->channel) {
			return;
		}

		$this->channel->close();
		$this->channel = null;

		$this->num = 0;
	}

	private function make(): void
	{
		$this->num++;

		try {
			$this->channel->push($this->factory->create());
		}
		catch (Throwable $throwable) {
			$this->num--;
			throw new RuntimeException($throwable->getMessage(), $throwable->getCode(), $throwable);
		}
	}
}