<?php

declare(strict_types=1);

namespace LogDB\Reader;

use Closure;
use LogDB\Errors\LogDBConfigError;

/**
 * Configuration for `LogDBReader`.
 *
 * Two options are required: `endpoint` (the LogDB REST API base — the SDK
 * appends `/log/sdk/...` paths) and `apiKey` (sent as `X-LogDB-ApiKey`).
 * Everything else has sensible defaults.
 */
final class LogDBReaderOptions
{
    /**
     * @param string                 $endpoint               REST API base URL. The SDK appends `/log/sdk/event/query`, `/log/sdk/cache/query`, etc. Pass the BASE only — e.g. `https://test-01.logdb.site/rest-api`, NOT the full path.
     * @param string                 $apiKey                 Account-scoped API key. Sent as the `X-LogDB-ApiKey` request header on every call.
     * @param int                    $maxRetries             Retry attempts per request on transient transport failures (5xx, 429, network).
     * @param int                    $retryDelay             Initial retry delay (ms).
     * @param float                  $retryBackoffMultiplier Exponential backoff multiplier. ±20% jitter is applied automatically.
     * @param int                    $requestTimeout         Per-request curl deadline (ms).
     * @param array<string,string>   $headers                Extra HTTP headers sent with every request. Useful for tracing / proxy auth.
     * @param bool                   $enableDebugLogging     Print request/response summaries to `error_log`.
     * @param Closure|null           $onError                Called when a request fails after retries. Signature: `function (\Throwable $err, string $endpoint): void`.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $apiKey,
        public readonly int $maxRetries = 3,
        public readonly int $retryDelay = 500,
        public readonly float $retryBackoffMultiplier = 2.0,
        public readonly int $requestTimeout = 30_000,
        public readonly array $headers = [],
        public readonly bool $enableDebugLogging = false,
        public readonly ?Closure $onError = null,
    ) {
        if ($endpoint === '') {
            throw new LogDBConfigError('LogDBReaderOptions: endpoint is required');
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            throw new LogDBConfigError(
                "LogDBReaderOptions: endpoint must be an http(s) URL, got: {$endpoint}",
            );
        }
        if ($apiKey === '') {
            throw new LogDBConfigError('LogDBReaderOptions: apiKey is required');
        }
    }

    /** Endpoint with trailing slash trimmed. */
    public function normalisedEndpoint(): string
    {
        return rtrim($this->endpoint, '/');
    }
}
