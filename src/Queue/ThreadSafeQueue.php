<?php
declare(strict_types=1);

namespace SuperKernel\Pool;

use Swoole\Lock;
use function array_shift;
use function microtime;
use function usleep;
use const SWOOLE_MUTEX;

final class ThreadSafeQueue
{
	private array $queue = [];

	private Lock $lock;

	public function __construct()
	{
		$this->lock = new Lock(SWOOLE_MUTEX);
	}

	public function push($item): void
	{
		$this->lock->lock();
		$this->queue[] = $item;
		$this->lock->unlock();
	}

	public function pop(float $timeout = -1): mixed
	{
		$start = microtime(true);
		while (true) {
			$this->lock->lock();
			if (!empty($this->queue)) {
				$item = array_shift($this->queue);
				$this->lock->unlock();
				return $item;
			}
			$this->lock->unlock();

			if ($timeout > 0 && (microtime(true) - $start) >= $timeout) {
				return null;
			}
			usleep(1000);
		}
	}

	public function isEmpty(): bool
	{
		return empty($this->queue);
	}
}