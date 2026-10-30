<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Frequency;

use Closure;
use Throwable;
use UnexpectedValueException;

use function is_finite;

/**
 * Reads finite seconds from the non-yielding monotonic clock of a frequency strategy.
 *
 * @internal
 */
final readonly class FrequencyClock
{
    /** @param Closure(): float $clock Non-yielding monotonic seconds */
    public function __construct(private Closure $clock)
    {
    }

    /** @throws Throwable When the clock fails or returns a non-finite timestamp */
    public function now(): float
    {
        $now = ($this->clock)();
        if (!is_finite($now)) {
            throw new UnexpectedValueException('The frequency clock must return finite monotonic seconds.');
        }

        return $now;
    }
}
