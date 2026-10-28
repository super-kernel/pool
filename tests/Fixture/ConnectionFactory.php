<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Fixture;

use Closure;
use SuperKernel\Pool\Contract\ConnectionFactoryInterface;
use SuperKernel\Pool\Contract\ConnectionInterface;

/** @implements ConnectionFactoryInterface<Connection> */
final class ConnectionFactory implements ConnectionFactoryInterface
{
    /** @var list<Connection> */
    public array $created = [];

    /** @var list<Connection> */
    public array $closed = [];

    /** @var (Closure(): Connection)|null */
    public ?Closure $creator = null;

    /** @var (Closure(Connection): bool)|null */
    public ?Closure $healthCheck = null;

    /** @var (Closure(Connection): void)|null */
    public ?Closure $closer = null;

    public function create(): ConnectionInterface
    {
        $connection = null === $this->creator ? new Connection() : ($this->creator)();
        $this->created[] = $connection;
        $connection->healthCheck = fn(Connection $value): bool => null === $this->healthCheck
            ? $value->healthy
            : ($this->healthCheck)($value);
        $connection->closer = function (Connection $value): void {
            $this->closed[] = $value;
            if (null !== $this->closer) {
                ($this->closer)($value);
            }
        };

        return $connection;
    }
}
