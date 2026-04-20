<?php

declare(strict_types=1);

namespace LogDB\Reader;

use LogDB\Errors\LogDBAuthError;
use LogDB\Errors\LogDBConfigError;
use LogDB\Errors\LogDBNetworkError;
use LogDB\Errors\LogDBTimeoutError;
use LogDB\Resilience\RetryPolicy;

/**
 * REST/JSON transport for `LogDBReader`. Mirrors `OtlpHttpTransport` —
 * native curl, no Guzzle, no PSR-18, retry policy from the writer side.
 *
 * Failure mapping → typed errors (same scheme as the writer):
 *   - 401 / 403          → LogDBAuthError
 *   - 400 / 404 / 422    → LogDBConfigError
 *   - timeout            → LogDBTimeoutError
 *   - network / 5xx / 429 → LogDBNetworkError (retried per options)
 */
final class ReaderTransport
{
    private const HEADER_API_KEY = 'X-LogDB-ApiKey';

    private readonly string $baseUrl;
    private readonly int $timeoutMs;
    private readonly int $maxRetries;
    private readonly int $retryDelay;
    private readonly float $retryBackoffMultiplier;
    private readonly bool $debug;
    /** @var array<string, string> */
    private readonly array $headers;

    private ?\CurlHandle $curl = null;

    /**
     * Optional injection point for tests — receives ($method, $url, $body, $headers, $timeoutMs)
     * and returns [statusCode, body].
     *
     * @var (callable(string $method, string $url, ?string $body, array<string, string> $headers, int $timeoutMs): array{0: int, 1: string})|null
     */
    private $sender;

    public function __construct(
        LogDBReaderOptions $options,
        ?callable $sender = null,
    ) {
        $this->baseUrl = $options->normalisedEndpoint();
        $this->timeoutMs = $options->requestTimeout;
        $this->maxRetries = $options->maxRetries;
        $this->retryDelay = $options->retryDelay;
        $this->retryBackoffMultiplier = $options->retryBackoffMultiplier;
        $this->debug = $options->enableDebugLogging;
        $this->headers = array_merge(
            [
                'content-type' => 'application/json',
                'accept' => 'application/json',
                self::HEADER_API_KEY => $options->apiKey,
            ],
            $options->headers,
        );
        $this->sender = $sender;
    }

    /**
     * POST a JSON body and return the decoded response array. The SDK
     * endpoints we target always return an object envelope (never a bare
     * array), so the return is narrowed to the associative shape.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $body): array
    {
        $json = json_encode(self::stripNulls($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new LogDBConfigError('Failed to JSON-encode request: ' . json_last_error_msg());
        }

        $result = $this->execute(method: 'POST', path: $path, body: $json);
        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * GET a path and return the decoded response array.
     *
     * @param array<string, string|int|null> $query  appended as `?k=v&...` after url-encoding
     * @return array<string, mixed>|list<mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $qs = '';
        $clean = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
        if ($clean !== []) {
            $qs = '?' . http_build_query($clean);
        }
        return $this->execute(method: 'GET', path: $path . $qs, body: null);
    }

    public function close(): void
    {
        if ($this->curl !== null) {
            curl_close($this->curl);
            $this->curl = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function execute(string $method, string $path, ?string $body): array
    {
        $url = $this->baseUrl . $path;
        $self = $this;

        return RetryPolicy::execute(
            fn () => $self->doOnce($method, $url, $body),
            maxRetries: $this->maxRetries,
            retryDelay: $this->retryDelay,
            retryBackoffMultiplier: $this->retryBackoffMultiplier,
        );
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function doOnce(string $method, string $url, ?string $body): array
    {
        if ($this->debug) {
            error_log("[logdb-reader] {$method} {$url}" . ($body !== null ? ' body=' . substr($body, 0, 200) : ''));
        }

        if ($this->sender !== null) {
            [$status, $responseBody] = ($this->sender)($method, $url, $body, $this->headers, $this->timeoutMs);
        } else {
            [$status, $responseBody] = $this->doCurl($method, $url, $body);
        }

        if ($status >= 200 && $status < 300) {
            if ($responseBody === '') {
                return [];
            }
            $decoded = json_decode($responseBody, true);
            if (!is_array($decoded)) {
                throw new LogDBNetworkError("Reader response from {$url} was not valid JSON: " . self::truncate($responseBody, 200));
            }
            return $decoded;
        }

        $snippet = $responseBody === '' ? '' : ': ' . self::truncate($responseBody, 500);
        $message = "Reader {$status} from {$url}{$snippet}";

        match (true) {
            $status === 401, $status === 403 => throw new LogDBAuthError($message),
            $status === 400, $status === 404, $status === 422 => throw new LogDBConfigError($message),
            default => throw new LogDBNetworkError($message),
        };
    }

    /** @return array{0: int, 1: string} */
    private function doCurl(string $method, string $url, ?string $body): array
    {
        $ch = $this->getCurl();
        $headerLines = [];
        foreach ($this->headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->timeoutMs, 10_000),
            CURLOPT_FAILONERROR => false,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '';
        }

        curl_setopt_array($ch, $opts);

        /** @var string|false $response */
        $response = curl_exec($ch);

        if ($response === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            if (in_array($errno, [CURLE_OPERATION_TIMEOUTED, 28], true)) {
                throw new LogDBTimeoutError("Reader request to {$url} timed out: {$err}");
            }
            throw new LogDBNetworkError("Reader request to {$url} failed: {$err}");
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [(int) $status, $response];
    }

    private function getCurl(): \CurlHandle
    {
        if ($this->curl === null) {
            $ch = curl_init();
            if ($ch === false) {
                throw new LogDBNetworkError('Failed to initialise curl handle for reader');
            }
            $this->curl = $ch;
        }
        return $this->curl;
    }

    /**
     * Drop null entries from a request body so the .NET API sees them as
     * "unset" rather than "explicit null". Matters for fields like
     * `application` where null = "no filter" but the JSON literal `null`
     * could be parsed differently than absence.
     *
     * @param array<string, mixed> $arr
     * @return array<string, mixed>
     */
    private static function stripNulls(array $arr): array
    {
        return array_filter($arr, static fn ($v): bool => $v !== null);
    }

    private static function truncate(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : substr($s, 0, $max) . '…';
    }
}
