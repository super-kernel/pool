<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

use Throwable;

/** @template T of ConnectionInterface */
interface ConnectionFactoryInterface
{
    /**
     * Create a fresh connection object owned exclusively by this pool.
     * The pool calls connect() before exposing it to a borrower.
     *
     * @return T
     *
     * @throws Throwable When connection creation fails
     */
    public function create(): ConnectionInterface;
}
