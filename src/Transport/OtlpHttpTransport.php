<?php

declare(strict_types=1);

namespace LogDB\Transport;

use LogDB\Errors\LogDBAuthError;
use LogDB\Errors\LogDBConfigError;
use LogDB\Errors\LogDBNetworkError;
use LogDB\Errors\LogDBTimeoutError;
use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;

/**
 * OTLP HTTP/JSON transport using PHP's native `ext-curl`.
 *
 * Failure mapping → typed errors:
 *   - 401 / 403          → LogDBAuthError
 *   - 400 / 404 / 422    → LogDBConfigError
 *   - timeout            → LogDBTimeoutError
 *   - network / 5xx / 429 → LogDBNetworkError
 *
 * Retry / circuit-breaker logic lives one layer up in `LogDBClient`.
 */
final class OtlpHttpTransport implements TransportInterface
{
    public const LOGS_PATH = '/v1/logs';
    public const METRICS_PATH = '/v1/metrics';

    private readonly string $logsUrl;
    private readonly string $metricsUrl;

    /** @var array{apiKey: ?string, application: ?string, environment: ?string, collection: ?string} */
    private readonly array $defaults;

    /** @var array<string, string> */
    private readonly array $headers;

    /** Persistent curl handle so the underlying TCP/TLS connection can be reused. */
    private ?\CurlHandle $curl = null;

    /** Optional injection point for tests — receives the request and returns [statusCode, body]. */
    private $sender;

    /**
     * @param array<string, string> $headers
     * @param (callable(string $url, string $body, array<string, string> $headers, int $timeoutMs): array{0: int, 1: string})|null $sender
     */
    public function __construct(
        string $endpoint,
        ?string $apiKey,
        ?string $defaultApplication,
        ?string $defaultEnvironment,
        ?string $defaultCollection,
        private readonly int $requestTimeoutMs,
        array $headers = [],
        ?callable $sender = null,
    ) {
        $endpoint = rtrim($endpoint, '/');
        $this->logsUrl = $endpoint . self::LOGS_PATH;
        $this->metricsUrl = $endpoint . self::METRICS_PATH;
        $this->defaults = [
            'apiKey' => $apiKey,
            'application' => $defaultApplication,
            'environment' => $defaultEnvironment,
            'collection' => $defaultCollection,
        ];
        $this->headers = array_merge(['content-type' => 'application/json'], $headers);
        $this->sender = $sender;
    }

    public function sendLog(Log $log): void
    {
        $this->sendLogBatch([$log]);
    }

    /** @param Log[] $logs */
    public function sendLogBatch(array $logs): void
    {
        if ($logs === []) {
            return;
        }
        $body = OtlpMappers::buildLogsRequest($logs, $this->defaults);
        $this->post($this->logsUrl, $body);
    }

    public function sendLogBeat(LogBeat $beat): void
    {
        $this->sendLogBeatBatch([$beat]);
    }

    /** @param LogBeat[] $beats */
    public function sendLogBeatBatch(array $beats): void
    {
        if ($beats === []) {
            return;
        }
        $body = OtlpMappers::buildMetricsRequest($beats, $this->defaults);
        $this->post($this->metricsUrl, $body);
    }

    public function sendLogCache(LogCache $cache): void
    {
        $this->sendLogCacheBatch([$cache]);
    }

    /** @param LogCache[] $caches */
    public function sendLogCacheBatch(array $caches): void
    {
        if ($caches === []) {
            return;
        }
        $body = OtlpMappers::buildCacheRequest($caches, $this->defaults);
        $this->post($this->logsUrl, $body);
    }

    public function close(): void
    {
        if ($this->curl !== null) {
            curl_close($this->curl);
            $this->curl = null;
        }
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): void
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new LogDBConfigError('Failed to JSON-encode OTLP request: ' . json_last_error_msg());
        }

        if ($this->sender !== null) {
            [$status, $responseBody] = ($this->sender)($url, $json, $this->headers, $this->requestTimeoutMs);
        } else {
            [$status, $responseBody] = $this->doCurl($url, $json);
        }

        if ($status >= 200 && $status < 300) {
            return;
        }

        $snippet = $responseBody === '' ? '' : ': ' . self::truncate($responseBody, 500);
        $message = "OTLP {$status} from {$url}{$snippet}";

        match (true) {
            $status === 401, $status === 403 => throw new LogDBAuthError($message),
            $status === 400, $status === 404, $status === 422 => throw new LogDBConfigError($message),
            default => throw new LogDBNetworkError($message),
        };
    }

    /** @return array{0: int, 1: string} */
    private function doCurl(string $url, string $body): array
    {
        $ch = $this->getCurl();
        $headerLines = [];
        foreach ($this->headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $this->requestTimeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->requestTimeoutMs, 10_000),
            CURLOPT_FAILONERROR => false,
        ]);

        /** @var string|false $response */
        $response = curl_exec($ch);

        if ($response === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            if (in_array($errno, [CURLE_OPERATION_TIMEOUTED, 28], true)) {
                throw new LogDBTimeoutError("OTLP request to {$url} timed out: {$err}");
            }
            throw new LogDBNetworkError("OTLP request to {$url} failed: {$err}");
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [(int) $status, $response];
    }

    private function getCurl(): \CurlHandle
    {
        if ($this->curl === null) {
            $ch = curl_init();
            if ($ch === false) {
                throw new LogDBNetworkError('Failed to initialise curl handle');
            }
            $this->curl = $ch;
        }
        return $this->curl;
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }

    public function __destruct()
    {
        $this->close();
    }
}
