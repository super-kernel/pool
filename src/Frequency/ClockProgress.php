<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Frequency;

use LogicException;
use Throwable;

/**
 * Tracks the first and latest readings of a monotonic frequency clock and rejects readings that move backwards.
 *
 * @internal
 */
final class ClockProgress
{
    private readonly float $startedAt;

    private float $updatedAt;

    /** @throws Throwable When the clock fails or returns a non-finite timestamp */
    public function __construct(private readonly FrequencyClock $clock)
    {
        $this->startedAt = $clock->now();
        $this->updatedAt = $this->startedAt;
    }

    /**
     * Move to the current clock reading.
     *
     * @return float Seconds elapsed since the previous reading
     *
     * @throws Throwable When the clock fails, moves backwards, or returns a non-finite timestamp
     */
    public function advance(): float
    {
        $now = $this->clock->now();
        if ($now < $this->updatedAt) {
            throw new LogicException('The frequency clock must not move backwards.');
        }
        $elapsed = $now - $this->updatedAt;
        $this->updatedAt = $now;

        return $elapsed;
    }

    /** @return float Seconds between the first and the latest clock reading */
    public function observedSeconds(): float
    {
        return $this->updatedAt - $this->startedAt;
    }
}
