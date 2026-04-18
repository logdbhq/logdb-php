<?php

declare(strict_types=1);

namespace LogDB\Resilience;

use Closure;

final class Backoff
{
    /**
     * Compute exponential backoff delay with ±20% jitter.
     *
     * @param int          $attempt     0-indexed attempt number (0 = first retry)
     * @param int          $baseDelayMs initial delay
     * @param float        $multiplier  exponent base
     * @param Closure|null $random      injectable RNG returning a float in [0, 1); defaults to `mt_rand() / mt_getrandmax()`
     */
    public static function compute(
        int $attempt,
        int $baseDelayMs,
        float $multiplier,
        ?Closure $random = null,
    ): int {
        $random ??= static fn (): float => mt_rand() / mt_getrandmax();
        $exact = $baseDelayMs * ($multiplier ** $attempt);
        $jitter = 0.8 + $random() * 0.4;
        return (int) round($exact * $jitter);
    }

    /** Sleep for the given number of milliseconds. Wrapped for test stubbing. */
    public static function sleepMs(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        usleep($ms * 1000);
    }
}
