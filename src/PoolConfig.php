<?php
declare(strict_types=1);

namespace SuperKernel\Pool;

use SuperKernel\Pool\Contract\PoolConfigInterface;

final readonly class PoolConfig implements PoolConfigInterface
{
	private int $min_connections;

	private int $max_connections;

	private float $connect_timeout;

	private float $wait_timeout;

	private int $heartbeat;

	private float $max_idle_time;

	public function __construct(
		?int   $min_connections = null,
		?int   $max_connections = null,
		?float $connect_timeout = null,
		?float $wait_timeout = null,
		?int   $heartbeat = null,
		?float $max_idle_time = null,
	)
	{
		$this->min_connections = $min_connections ?? 1;
		$this->max_connections = $max_connections ?? 10;
		$this->connect_timeout = $connect_timeout ?? 10.0;
		$this->wait_timeout    = $wait_timeout ?? 3.0;
		$this->heartbeat       = $heartbeat ?? -1;
		$this->max_idle_time   = $max_idle_time ?? 60.0;
	}

	public function getMinConnections(): int
	{
		return $this->min_connections;
	}

	public function getMaxConnections(): int
	{
		return $this->max_connections;
	}

	public function getConnectTimeout(): float
	{
		return $this->connect_timeout;
	}

	public function getWaitTimeout(): float
	{
		return $this->wait_timeout;
	}

	public function getHeartbeat(): int
	{
		return $this->heartbeat;
	}

	public function getMaxIdleTime(): float
	{
		return $this->max_idle_time;
	}
}