<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Lifecycle;

use SuperKernel\Pool\Exception\PoolException;
use Throwable;

/**
 * Retries each inactive pending cleanup once and releases the capacity that a successful retry frees.
 *
 * @internal
 */
final class CleanupRetry
{
    /**
     * @return Throwable|null The first cleanup failure after attempting every inactive pending resource
     *
     * @throws PoolException When a completed cleanup cannot release its capacity
     */
    public static function retry(ConnectionLifecycle $lifecycle): ?Throwable
    {
        $failure = null;
        foreach ($lifecycle->pendingKeys() as $key) {
            $pending = $lifecycle->pending($key);
            if (null === $pending || $pending['closing']) {
                continue;
            }
            try {
                $lifecycle->destroy($pending['connection'], $pending['reservation']);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            } finally {
                if (null !== $pending['reservation']) {
                    $lifecycle->releaseCapacity($pending['reservation']);
                }
            }
        }

        return $failure;
    }
}
