<?php

declare(strict_types=1);

namespace LogDB\Resilience;

use Closure;
use LogDB\Errors\LogDBAuthError;
use LogDB\Errors\LogDBConfigError;
use LogDB\Errors\LogDBNetworkError;
use LogDB\Errors\LogDBTimeoutError;
use Throwable;

/**
 * Synchronous retry helper.
 *
 * Retryable: `LogDBNetworkError`, `LogDBTimeoutError` (HTTP 5xx, 429, network).
 * Non-retryable: `LogDBAuthError`, `LogDBConfigError` — rethrown immediately.
 */
final class RetryPolicy
{
    /**
     * @template T
     * @param Closure(): T  $fn
     * @return T
     */
    public static function execute(
        Closure $fn,
        int $maxRetries,
        int $retryDelay,
        float $retryBackoffMultiplier,
        ?Closure $random = null,
        ?Closure $sleeper = null,
    ): mixed {
        $sleeper ??= static fn (int $ms) => Backoff::sleepMs($ms);

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            try {
                return $fn();
            } catch (LogDBAuthError | LogDBConfigError $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempt === $maxRetries) {
                    break;
                }
                $delay = Backoff::compute($attempt, $retryDelay, $retryBackoffMultiplier, $random);
                $sleeper($delay);
                $attempt++;
            }
        }

        // Re-throw the last seen error, ensuring it's a typed LogDB error.
        if ($lastError instanceof LogDBNetworkError || $lastError instanceof LogDBTimeoutError) {
            throw $lastError;
        }
        $message = $lastError instanceof Throwable ? $lastError->getMessage() : 'unknown error';
        throw new LogDBNetworkError($message, $lastError);
    }
}
