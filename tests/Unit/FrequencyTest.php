<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperKernel\Pool\Frequency\ConstantFrequency;
use SuperKernel\Pool\Frequency\SmartLowFrequency;
use Throwable;
use UnexpectedValueException;

use const INF;
use const NAN;
use const PHP_FLOAT_MAX;

final class FrequencyTest extends TestCase
{
    /** @return iterable<string, array{float, float, bool}> */
    public static function constantRates(): iterable
    {
        yield 'idle' => [
            0.0,
            1.0,
            true,
        ];
        yield 'below threshold' => [
            0.5,
            1.0,
            true,
        ];
        yield 'threshold boundary' => [
            1.0,
            1.0,
            false,
        ];
        yield 'above threshold' => [
            2.0,
            1.0,
            false,
        ];
    }

    /** @return iterable<string, array{float, float}> */
    public static function invalidConstantOptions(): iterable
    {
        yield 'negative rate' => [
            -1.0,
            1.0,
        ];
        yield 'infinite rate' => [
            INF,
            1.0,
        ];
        yield 'not a number rate' => [
            NAN,
            1.0,
        ];
        yield 'zero threshold' => [
            1.0,
            0.0,
        ];
        yield 'negative threshold' => [
            1.0,
            -1.0,
        ];
        yield 'infinite threshold' => [
            1.0,
            INF,
        ];
        yield 'not a number threshold' => [
            1.0,
            NAN,
        ];
    }

    /** @return iterable<string, array{float, float}> */
    public static function invalidSmartOptions(): iterable
    {
        yield 'zero threshold' => [
            0.0,
            10.0,
        ];
        yield 'negative threshold' => [
            -1.0,
            10.0,
        ];
        yield 'infinite threshold' => [
            INF,
            10.0,
        ];
        yield 'not a number threshold' => [
            NAN,
            10.0,
        ];
        yield 'zero window' => [
            1.0,
            0.0,
        ];
        yield 'negative window' => [
            1.0,
            -1.0,
        ];
        yield 'infinite window' => [
            1.0,
            INF,
        ];
        yield 'not a number window' => [
            1.0,
            NAN,
        ];
    }

    /** @throws Throwable When the frequency strategy cannot be constructed or evaluated */
    #[DataProvider('constantRates')]
    public function testConstantFrequencyDoesNotChangeWithBorrowing(float $rate, float $threshold, bool $low): void
    {
        $frequency = new ConstantFrequency($rate, $threshold);

        for ($index = 0; 3 > $index; ++$index) {
            $frequency->recordBorrow();
            self::assertSame($rate, $frequency->frequency());
            self::assertSame($low, $frequency->isLowFrequency());
        }
    }

    #[DataProvider('invalidConstantOptions')]
    public function testConstantFrequencyRejectsInvalidOptions(float $rate, float $threshold): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConstantFrequency($rate, $threshold);
    }

    /** @throws Throwable When strategy construction fails */
    #[DataProvider('invalidSmartOptions')]
    public function testSmartFrequencyRejectsInvalidOptions(float $threshold, float $window): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SmartLowFrequency($threshold, $window);
    }

    /** @throws Throwable When the clock or frequency evaluation fails */
    public function testSmartFrequencyWaitsForTheInitialObservationWindow(): void
    {
        $now = 0.0;
        $frequency = new SmartLowFrequency(1.0, 10.0, static function () use (&$now): float {
            return $now;
        });

        self::assertSame(0.0, $frequency->frequency());
        self::assertFalse($frequency->isLowFrequency());
        $now = 9.999;
        self::assertFalse($frequency->isLowFrequency());
        $now = 10.0;
        self::assertTrue($frequency->isLowFrequency());
    }

    /** @throws Throwable When the clock or frequency evaluation fails */
    public function testSmartFrequencyDecaysWhileIdleAndRecoversWithNewDemand(): void
    {
        $now = 0.0;
        $frequency = new SmartLowFrequency(0.25, 10.0, static function () use (&$now): float {
            return $now;
        });

        for ($index = 0; 10 > $index; ++$index) {
            $frequency->recordBorrow();
        }
        self::assertEqualsWithDelta(1.0, $frequency->frequency(), 1e-12);
        $now = 5.0;
        self::assertEqualsWithDelta(0.606530659713, $frequency->frequency(), 1e-12);
        $now = 10.0;
        self::assertEqualsWithDelta(0.367879441171, $frequency->frequency(), 1e-12);
        self::assertFalse($frequency->isLowFrequency());
        $now = 20.0;
        self::assertTrue($frequency->isLowFrequency());
        self::assertEqualsWithDelta(0.135335283237, $frequency->frequency(), 1e-12);
        $frequency->recordBorrow();
        $frequency->recordBorrow();
        self::assertFalse($frequency->isLowFrequency());
        self::assertEqualsWithDelta(0.335335283237, $frequency->frequency(), 1e-12);
    }

    /** @throws Throwable When the clock or frequency evaluation fails */
    public function testInvalidClockMovementDoesNotChangeRecordedDemand(): void
    {
        $now = 0.0;
        $frequency = new SmartLowFrequency(1.0, 1.0, static function () use (&$now): float {
            return $now;
        });
        $frequency->recordBorrow();
        $now = -1.0;
        try {
            $frequency->recordBorrow();
            self::fail('A regressing clock must not update demand.');
        } catch (LogicException $exception) {
            self::assertSame('The frequency clock must not move backwards.', $exception->getMessage());
        }
        $now = 1.0;
        self::assertEqualsWithDelta(0.367879441171, $frequency->frequency(), 1e-12);
    }

    /** @throws Throwable When the supplied clock fails */
    public function testSmartFrequencyRejectsNonFiniteClockValues(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new SmartLowFrequency(clock: static fn(): float => NAN);
    }

    /** @throws Throwable When the clock or frequency evaluation fails */
    public function testSmartFrequencySaturatesInsteadOfReturningAnInfiniteRate(): void
    {
        $frequency = new SmartLowFrequency(windowSeconds: 1e-320, clock: static fn(): float => 0.0);
        $frequency->recordBorrow();
        $frequency->recordBorrow();

        self::assertSame(PHP_FLOAT_MAX, $frequency->frequency());
        self::assertFalse($frequency->isLowFrequency());
    }
}
