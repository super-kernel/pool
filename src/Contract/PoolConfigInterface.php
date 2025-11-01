<?php
declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

/**
 * Connection pool configuration interface
 *
 * Used to provide configuration parameters for the connection pool.
 */
interface PoolConfigInterface
{
	/**
	 * Get the minimum number of connections in the connection pool.
	 *
	 * These connections are created in advance when the pool is initialized.
	 *
	 * @return int
	 */
	public function getMinConnections(): int;

	/**
	 * Get the maximum number of connections in the connection pool.
	 *
	 * New connections are allowed when there are insufficient idle connections and the maximum number has not been
	 * reached.
	 *
	 * @return int
	 */
	public function getMaxConnections(): int;

	/**
	 * Get connection timeout (seconds).
	 *
	 * When a coroutine acquires a connection, it will throw an exception if the waiting time exceeds this value.
	 *
	 * @return float
	 */
	public function getConnectTimeout(): float;

	/**
	 * Get the longest time (in seconds) to wait for a connection to become available.
	 *
	 * The time a coroutine waits when the connection pool reaches its maximum value and there are no idle connections.
	 *
	 * @return float
	 */
	public function getWaitTimeout(): float;

	/**
	 * Get the heartbeat detection interval (in seconds).
	 *
	 * The connection pool periodically performs heartbeat checks on idle connections to ensure connection health.
	 *
	 * @return int
	 */
	public function getHeartbeat(): int;

	/**
	 * Get the maximum idle time of the connection (in seconds).
	 *
	 * When the connection idle time exceeds this value, the connection pool will actively close the connection to
	 * release resources.
	 *
	 * @return float
	 */
	public function getMaxIdleTime(): float;
}