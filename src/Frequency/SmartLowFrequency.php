<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Frequency;

use Closure;
use InvalidArgumentException;
use SuperKernel\Pool\Contract\FrequencyInterface;
use Throwable;

use function exp;
use function hrtime;
use function is_finite;
use function min;

use const PHP_FLOAT_MAX;

/** Estimates demand with exponential decay and one initial observation window before shrinking. */
final class SmartLowFrequency implements FrequencyInterface
{
    private readonly ClockProgress $progress;

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
        $this->progress = new ClockProgress(
            new FrequencyClock($clock ?? static fn(): float => hrtime(true) / 1_000_000_000),
        );
    }

    public function recordBorrow(): void
    {
        $this->rate *= exp(-($this->progress->advance() / $this->windowSeconds));
        $this->rate = min(PHP_FLOAT_MAX, $this->rate + 1.0 / $this->windowSeconds);
    }

    public function frequency(): float
    {
        $this->rate *= exp(-($this->progress->advance() / $this->windowSeconds));

        return $this->rate;
    }

    public function isLowFrequency(): bool
    {
        $this->rate *= exp(-($this->progress->advance() / $this->windowSeconds));

        return $this->progress->observedSeconds() >= $this->windowSeconds
            && $this->rate < $this->lowFrequencyThreshold;
    }
}
