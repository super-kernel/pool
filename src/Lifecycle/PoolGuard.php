<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Lifecycle;

use SuperKernel\Pool\Contract\CapacityInterface;
use SuperKernel\Pool\Exception\PoolClosedException;
use SuperKernel\Pool\Exception\PoolException;

/**
 * Records pool shutdown and rejects pool operations after shutdown or outside the owning process or thread.
 *
 * @internal
 */
final class PoolGuard
{
    private bool $closed = false;

    /** @param string $context Identity of the execution unit that created the pool */
    public function __construct(private readonly CapacityInterface $backend, private readonly string $context)
    {
    }

    public function isClosed(): bool
    {
        return $this->closed || $this->backend->isClosed();
    }

    /** @throws PoolClosedException When the pool or shared backend has closed */
    public function assertOpen(): void
    {
        if ($this->closed || $this->backend->isClosed()) {
            throw new PoolClosedException('The connection pool is closed.');
        }
    }

    /** @throws PoolException When accessing resources from a foreign execution unit */
    public function assertOwner(): void
    {
        $this->backend->assertContext();
        if ($this->context !== $this->backend->contextId()) {
            throw new PoolException('Create each resource-owning pool in its own process or thread.');
        }
    }

    /**
     * Close the backend once from the owning execution unit and reject later pool operations.
     *
     * @throws PoolException When called from a foreign execution unit or the backend rejects closing in this context
     */
    public function close(): void
    {
        if ($this->context !== $this->backend->contextId()) {
            throw new PoolException('Create each resource-owning pool in its own process or thread.');
        }
        // The backend marks shutdown before waking waiters and validates initialized runtime access.
        if (!$this->closed) {
            $this->backend->close();
        }
        $this->closed = true;
    }
}
