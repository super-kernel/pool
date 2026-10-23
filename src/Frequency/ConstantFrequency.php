<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Frequency;

use InvalidArgumentException;
use SuperKernel\Pool\Contract\FrequencyInterface;

use function is_finite;

/** Supplies a fixed rate; the defaults preserve idle connection reuse. */
final readonly class ConstantFrequency implements FrequencyInterface
{
    /** @throws InvalidArgumentException When the rate is invalid or the threshold is not finite and positive */
    public function __construct(private float $frequency = 1.0, private float $lowFrequencyThreshold = 1.0)
    {
        if (!is_finite($frequency) || 0 > $frequency
            || !is_finite($lowFrequencyThreshold) || 0 >= $lowFrequencyThreshold) {
            throw new InvalidArgumentException(
                'A finite non-negative frequency and a positive threshold are required.',
            );
        }
    }

    /** Borrow attempts do not change the configured frequency. */
    public function recordBorrow(): void
    {
    }

    public function frequency(): float
    {
        return $this->frequency;
    }

    public function isLowFrequency(): bool
    {
        return $this->frequency < $this->lowFrequencyThreshold;
    }
}
