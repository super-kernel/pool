<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Tests\Support;

use Closure;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Throwable;

use function Swoole\Coroutine\run;

abstract class CoroutineTestCase extends TestCase
{
    private ?Throwable $failure = null;

    /**
     * @param Closure(): void $test
     *
     * @throws Throwable When the test or any child coroutine fails
     */
    protected function runCoroutine(Closure $test): void
    {
        run(function () use ($test): void {
            $this->capture($test);
        });

        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    /** @param Closure(): void $test */
    protected function startCoroutine(Closure $test): int
    {
        $id = Coroutine::create(function () use ($test): void {
            $this->capture($test);
        });
        self::assertIsInt($id);

        return $id;
    }

    /** @param Closure(): void $test */
    private function capture(Closure $test): void
    {
        try {
            $test();
        } catch (Throwable $exception) {
            $this->failure ??= $exception;
        }
    }
}
