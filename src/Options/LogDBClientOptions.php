<?php

declare(strict_types=1);

namespace LogDB\Options;

use Closure;
use LogDB\Errors\LogDBConfigError;

/**
 * Configuration for `LogDBClient`.
 *
 * Two options are required: `endpoint` and `apiKey` (server-side; on a relay
 * deployment the relay supplies the key). Everything else has sensible defaults.
 */
final class LogDBClientOptions
{
    /**
     * @param string                          $endpoint               Base URL the SDK POSTs to. The SDK appends `/v1/logs` and `/v1/metrics`. Server: the LogDB OTLP collector, e.g. `https://otlp.logdb.site`.
     * @param string|null                     $apiKey                 LogDB API key. Sent as the `logdb.apikey` resource attribute. Required for direct-to-collector mode; omit when posting to a relay endpoint that stamps it.
     * @param string|null                     $defaultApplication     Default `application` field stamped on logs. Recommended.
     * @param string                          $defaultEnvironment     Default `environment`. Defaults to `"production"`.
     * @param string                          $defaultCollection      Default `collection`. Defaults to `"logs"`.
     * @param bool                            $enableBatching         Buffer entries and flush in batches. Defaults to true.
     * @param int                             $batchSize              Max entries per type before triggering a flush.
     * @param int                             $flushInterval          Max time (ms) an entry waits in the buffer before flushing.
     * @param int                             $maxBatchRetries        Retry attempts on a failed batch before falling back to per-item retry.
     * @param int                             $maxRetries             Retry attempts per individual call.
     * @param int                             $retryDelay             Initial retry delay (ms).
     * @param float                           $retryBackoffMultiplier Exponential backoff multiplier.
     * @param bool                            $enableCircuitBreaker   Enable the sliding-window failure-rate breaker.
     * @param float                           $circuitBreakerFailureThreshold Failure rate (0..1) within the sampling window that trips the breaker.
     * @param int                             $circuitBreakerSamplingDuration Sliding window length (ms).
     * @param int                             $circuitBreakerDurationOfBreak  How long the breaker stays open before transitioning to half-open.
     * @param int                             $maxDegreeOfParallelism Reserved. PHP transports run sequentially today.
     * @param int                             $requestTimeout         Per-request deadline (ms).
     * @param array<string,string>            $headers                Extra HTTP headers sent with every request.
     * @param bool                            $enableDebugLogging     Print SDK lifecycle messages to error_log.
     * @param Closure|null                    $onError                Called when a send fails after retries. Signature: `function (\Throwable $err, ?array $batch): void`.
     * @param bool                            $flushOnShutdown        Register a `register_shutdown_function` callback that flushes pending entries before the request ends. Defaults to true.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly ?string $apiKey = null,
        public readonly ?string $defaultApplication = null,
        public readonly string $defaultEnvironment = 'production',
        public readonly string $defaultCollection = 'logs',
        public readonly bool $enableBatching = true,
        public readonly int $batchSize = 100,
        public readonly int $flushInterval = 5_000,
        public readonly int $maxBatchRetries = 2,
        public readonly int $maxRetries = 3,
        public readonly int $retryDelay = 1_000,
        public readonly float $retryBackoffMultiplier = 2.0,
        public readonly bool $enableCircuitBreaker = true,
        public readonly float $circuitBreakerFailureThreshold = 0.5,
        public readonly int $circuitBreakerSamplingDuration = 10_000,
        public readonly int $circuitBreakerDurationOfBreak = 30_000,
        public readonly int $maxDegreeOfParallelism = 4,
        public readonly int $requestTimeout = 30_000,
        public readonly array $headers = [],
        public readonly bool $enableDebugLogging = false,
        public readonly ?Closure $onError = null,
        public readonly bool $flushOnShutdown = true,
    ) {
        if ($endpoint === '') {
            throw new LogDBConfigError('LogDBClientOptions: endpoint is required');
        }
        if (!preg_match('#^https?://#i', $endpoint) && !str_starts_with($endpoint, '/')) {
            throw new LogDBConfigError(
                "LogDBClientOptions: endpoint must be an http(s) URL or a relative path, got: {$endpoint}",
            );
        }
    }

    /** Endpoint with any trailing slash trimmed. */
    public function normalisedEndpoint(): string
    {
        return rtrim($this->endpoint, '/');
    }
}
