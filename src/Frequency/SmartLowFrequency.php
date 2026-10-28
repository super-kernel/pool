<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Frequency;

use Closure;
use InvalidArgumentException;
use LogicException;
use SuperKernel\Pool\Contract\FrequencyInterface;
use Throwable;
use UnexpectedValueException;

use function exp;
use function hrtime;
use function is_finite;
use function min;

use const PHP_FLOAT_MAX;

/** Estimates demand with exponential decay and one initial observation window before shrinking. */
final class SmartLowFrequency implements FrequencyInterface
{
    /** @var Closure(): float */
    private readonly Closure $clock;

    private readonly float $startedAt;

    private float $updatedAt;

    private float $rate = 0.0;

    /**
     * @param (Closure(): float)|null $clock Non-yielding monotonic seconds; defaults to hrtime()
     *
     * @throws InvalidArgumentException When the threshold or window is not finite and positive
     * @throws Throwable                When the clock fails or returns a non-finite timestamp
     */
    public function __construct(
        private readonly float $lowFrequencyThreshold = 1.0,
        private readonly float $windowSeconds = 60.0,
        ?Closure               $clock = null,
    )
    {
        if (!is_finite($lowFrequencyThreshold) || 0 >= $lowFrequencyThreshold
            || !is_finite($windowSeconds) || 0 >= $windowSeconds) {
            throw new InvalidArgumentException(
                'A finite positive frequency threshold and observation window are required.',
            );
        }
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1_000_000_000;
        $this->startedAt = $this->timestamp();
        $this->updatedAt = $this->startedAt;
    }

    public function recordBorrow(): void
    {
        $this->decay();
        $this->rate = min(PHP_FLOAT_MAX, $this->rate + 1.0 / $this->windowSeconds);
    }

    public function frequency(): float
    {
        $this->decay();

        return $this->rate;
    }

    public function isLowFrequency(): bool
    {
        $frequency = $this->frequency();

        return $this->updatedAt - $this->startedAt >= $this->windowSeconds
            && $frequency < $this->lowFrequencyThreshold;
    }

    /** @throws Throwable When the clock fails, moves backwards, or returns a non-finite timestamp */
    private function decay(): void
    {
        $now = $this->timestamp();
        if ($now < $this->updatedAt) {
            throw new LogicException('The frequency clock must not move backwards.');
        }
        $this->rate *= exp(-(($now - $this->updatedAt) / $this->windowSeconds));
        $this->updatedAt = $now;
    }

    /** @throws Throwable When the clock fails or returns a non-finite timestamp */
    private function timestamp(): float
    {
        $now = ($this->clock)();
        if (!is_finite($now)) {
            throw new UnexpectedValueException('The frequency clock must return finite monotonic seconds.');
        }

        return $now;
    }
}
