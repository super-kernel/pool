<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Fixture;

use Closure;
use SuperKernel\Pool\Contract\ConnectionInterface;

final class Connection implements ConnectionInterface
{
    public bool $healthy = true;

    public bool $closed = false;

    public int $connects = 0;

    /** @var (Closure(self): void)|null */
    public ?Closure $connector = null;

    /** @var (Closure(self): bool)|null */
    public ?Closure $healthCheck = null;

    /** @var (Closure(self): void)|null */
    public ?Closure $closer = null;

    public function connect(): void
    {
        if (null !== $this->connector) {
            ($this->connector)($this);
        }
        ++$this->connects;
        $this->closed = false;
    }

    public function isHealthy(): bool
    {
        return null === $this->healthCheck ? $this->healthy : ($this->healthCheck)($this);
    }

    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    public function close(): void
    {
        if (null !== $this->closer) {
            ($this->closer)($this);
        }
        $this->closed = true;
    }
}
