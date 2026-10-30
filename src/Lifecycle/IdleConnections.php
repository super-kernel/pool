<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Lifecycle;

use SuperKernel\Pool\Contract\ConnectionInterface;

use function array_keys;
use function count;
use function spl_object_id;

/**
 * Holds the ready idle connections of one pool, keyed by object identity.
 *
 * @template T of ConnectionInterface
 *
 * @internal
 */
final class IdleConnections
{
    /** @var array<int, T> */
    private array $connections = [];

    /** @return list<int> Object identities of the connections that are idle at the time of the call */
    public function keys(): array
    {
        return array_keys($this->connections);
    }

    /** @return T|null The removed connection, or null when it is no longer idle */
    public function take(int $key): ?ConnectionInterface
    {
        $connection = $this->connections[$key] ?? null;
        unset($this->connections[$key]);

        return $connection;
    }

    /** @param T $connection */
    public function add(ConnectionInterface $connection): void
    {
        $this->connections[spl_object_id($connection)] = $connection;
    }

    public function count(): int
    {
        return count($this->connections);
    }

    /** @return array<int, T> Every idle connection, none of which remains held */
    public function drain(): array
    {
        $connections = $this->connections;
        $this->connections = [];

        return $connections;
    }
}
