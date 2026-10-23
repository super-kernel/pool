<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

use Throwable;

interface ConnectionInterface
{
    /**
     * Establish a usable connection. The pool calls this before the first borrow.
     * Clean up partially opened resources when establishment fails.
     *
     * @throws Throwable When connection establishment fails
     */
    public function connect(): void;

    /**
     * Check whether the connection can be reused. This operation may yield.
     *
     * @throws Throwable When the health check fails unexpectedly
     */
    public function isHealthy(): bool;

    /**
     * Replace the underlying connection while preserving this object's identity.
     * Only its current borrower may request reconnection; transaction and session state are lost.
     * The pool never retries an in-progress client operation automatically.
     *
     * @throws Throwable When cleanup or reconnection fails
     */
    public function reconnect(): void;

    /**
     * Release the underlying resource, including partially established state. This operation may yield.
     * The pool will not reuse the object after closing it, even if cleanup fails.
     * Make cleanup retryable: a failure retains its capacity until a later close completes successfully.
     *
     * @throws Throwable When resource cleanup fails
     */
    public function close(): void;
}
