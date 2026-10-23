<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Contract;

use Throwable;

/**
 * Tracks borrow demand for idle-connection maintenance.
 * Methods must not yield or perform I/O. Use a separate mutable strategy for each pool.
 */
interface FrequencyInterface
{
    /**
     * Record a valid borrow attempt before waiting for capacity, including attempts that later fail.
     *
     * @throws Throwable When demand tracking fails
     */
    public function recordBorrow(): void;

    /**
     * @return float Finite, non-negative borrow attempts per second
     *
     * @throws Throwable When the current frequency cannot be measured
     */
    public function frequency(): float;

    /**
     * Whether maintenance should shrink to its idle minimum instead of replenishing all unoccupied capacity.
     *
     * @throws Throwable When the current frequency cannot be evaluated
     */
    public function isLowFrequency(): bool;
}
