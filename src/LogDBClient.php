<?php

declare(strict_types=1);

namespace LogDB;

use Closure;
use DateTimeImmutable;
use LogDB\Batching\BatchEngine;
use LogDB\Errors\LogDBAuthError;
use LogDB\Errors\LogDBCircuitOpenError;
use LogDB\Errors\LogDBError;
use LogDB\Errors\LogDBTimeoutError;
use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Models\LogLevel;
use LogDB\Models\LogResponseStatus;
use LogDB\Options\LogDBClientOptions;
use LogDB\Resilience\CircuitBreaker;
use LogDB\Resilience\RetryPolicy;
use LogDB\Transport\OtlpHttpTransport;
use LogDB\Transport\TransportInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel as PsrLogLevel;
use Stringable;
use Throwable;

/**
 * LogDB client for PHP — universal OTLP HTTP/JSON writer.
 *
 * Implements PSR-3 `LoggerInterface` (via `AbstractLogger`) so it drops into any
 * framework (Laravel, Symfony, Slim, plain PHP) without an adapter. The typed
 * `logEntry()` API is used by builders and for full-fidelity sends.
 *
 * Lifecycle:
 *   - Construct with options (no I/O happens until first send)
 *   - First `info()` / `logEntry()` initialises the OTLP transport
 *   - `dispose()` flushes and releases resources
 *   - Auto-flush on shutdown via `register_shutdown_function` (controllable by
 *     `LogDBClientOptions::$flushOnShutdown`)
 */
final class LogDBClient extends AbstractLogger implements LogDBClientLike
{
    private readonly LogDBClientOptions $opts;
    private ?TransportInterface $transport = null;
    private ?BatchEngine $batch = null;
    private ?CircuitBreaker $breaker = null;
    private bool $disposed = false;
    private bool $shutdownRegistered = false;

    /** @var Closure[] */
    private array $errorListeners = [];

    public function __construct(
        LogDBClientOptions $options,
        ?TransportInterface $transport = null,
    ) {
        $this->opts = $options;

        if ($transport !== null) {
            $this->transport = $transport;
        }

        if ($this->opts->enableCircuitBreaker) {
            $this->breaker = new CircuitBreaker(
                failureThreshold: $this->opts->circuitBreakerFailureThreshold,
                samplingDurationMs: $this->opts->circuitBreakerSamplingDuration,
                durationOfBreakMs: $this->opts->circuitBreakerDurationOfBreak,
            );
        }
    }

    // ── PSR-3 ───────────────────────────────────────────────────

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $logLevel = self::psrLevelToLogLevel($level);
        $log = $this->buildLogFromPsr($logLevel, (string) $message, $context);
        $this->logEntry($log);
    }

    // ── Typed API (LogDBClientLike) ─────────────────────────────

    public function logEntry(Log $log): LogResponseStatus
    {
        $this->assertOpen();
        $normalised = $this->normaliseLog($log);

        if ($this->opts->enableBatching) {
            $this->ensureBatchEngine()->enqueueLog($normalised);
            return LogResponseStatus::Success;
        }
        $transport = $this->ensureTransport();
        return $this->runWithResilience(static fn () => $transport->sendLog($normalised));
    }

    /** @param Log[] $logs */
    public function sendLogBatch(array $logs): LogResponseStatus
    {
        if ($logs === []) {
            return LogResponseStatus::Success;
        }
        $this->assertOpen();
        $normalised = array_map(fn (Log $l) => $this->normaliseLog($l), $logs);
        $transport = $this->ensureTransport();
        return $this->runWithResilience(
            static fn () => $transport->sendLogBatch($normalised),
            $normalised,
        );
    }

    public function logBeat(LogBeat $beat): LogResponseStatus
    {
        $this->assertOpen();
        $normalised = $this->normaliseBeat($beat);

        if ($this->opts->enableBatching) {
            $this->ensureBatchEngine()->enqueueLogBeat($normalised);
            return LogResponseStatus::Success;
        }
        $transport = $this->ensureTransport();
        return $this->runWithResilience(static fn () => $transport->sendLogBeat($normalised));
    }

    /** @param LogBeat[] $beats */
    public function sendLogBeatBatch(array $beats): LogResponseStatus
    {
        if ($beats === []) {
            return LogResponseStatus::Success;
        }
        $this->assertOpen();
        $normalised = array_map(fn (LogBeat $b) => $this->normaliseBeat($b), $beats);
        $transport = $this->ensureTransport();
        return $this->runWithResilience(static fn () => $transport->sendLogBeatBatch($normalised));
    }

    public function logCache(LogCache $cache): LogResponseStatus
    {
        $this->assertOpen();
        $normalised = $this->normaliseCache($cache);

        if ($this->opts->enableBatching) {
            $this->ensureBatchEngine()->enqueueLogCache($normalised);
            return LogResponseStatus::Success;
        }
        $transport = $this->ensureTransport();
        return $this->runWithResilience(static fn () => $transport->sendLogCache($normalised));
    }

    /** @param LogCache[] $caches */
    public function sendLogCacheBatch(array $caches): LogResponseStatus
    {
        if ($caches === []) {
            return LogResponseStatus::Success;
        }
        $this->assertOpen();
        $normalised = array_map(fn (LogCache $c) => $this->normaliseCache($c), $caches);
        $transport = $this->ensureTransport();
        return $this->runWithResilience(static fn () => $transport->sendLogCacheBatch($normalised));
    }

    public function flush(): void
    {
        $this->batch?->flush();
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        try {
            $this->batch?->dispose();
        } finally {
            $this->transport?->close();
            $this->transport = null;
        }
    }

    /** Subscribe to per-error events. Pass a callback `function (\Throwable $err): void`. */
    public function onError(Closure $listener): void
    {
        $this->errorListeners[] = $listener;
    }

    // ── Internals ───────────────────────────────────────────────

    /**
     * @param Closure(): void                                            $fn
     * @param list<Log>|list<LogBeat>|list<LogCache>|null                $batchForCallback
     */
    private function runWithResilience(Closure $fn, ?array $batchForCallback = null): LogResponseStatus
    {
        $exec = fn () => RetryPolicy::execute(
            $fn,
            maxRetries: $this->opts->maxRetries,
            retryDelay: $this->opts->retryDelay,
            retryBackoffMultiplier: $this->opts->retryBackoffMultiplier,
        );

        try {
            if ($this->breaker !== null) {
                $this->breaker->execute($exec);
            } else {
                $exec();
            }
            return LogResponseStatus::Success;
        } catch (Throwable $err) {
            $this->emitError($err, $batchForCallback);
            return self::classifyStatus($err);
        }
    }

    /** @param list<Log>|list<LogBeat>|list<LogCache>|null $batch */
    private function emitError(Throwable $err, ?array $batch = null): void
    {
        if ($this->opts->onError !== null) {
            ($this->opts->onError)($err, $batch);
        }
        foreach ($this->errorListeners as $listener) {
            $listener($err);
        }
    }

    private function ensureTransport(): TransportInterface
    {
        if ($this->transport !== null) {
            return $this->transport;
        }
        $this->transport = new OtlpHttpTransport(
            endpoint: $this->opts->normalisedEndpoint(),
            apiKey: $this->opts->apiKey,
            defaultApplication: $this->opts->defaultApplication,
            defaultEnvironment: $this->opts->defaultEnvironment,
            defaultCollection: $this->opts->defaultCollection,
            requestTimeoutMs: $this->opts->requestTimeout,
            headers: $this->opts->headers,
        );
        return $this->transport;
    }

    private function ensureBatchEngine(): BatchEngine
    {
        if ($this->batch !== null) {
            return $this->batch;
        }
        $transport = $this->ensureTransport();
        $self = $this;
        $batch = new BatchEngine(
            transport: $transport,
            batchSize: $this->opts->batchSize,
            flushIntervalMs: $this->opts->flushInterval,
            onBatchError: function (Throwable $err, string $type, array $items) use ($self): void {
                $self->emitError($err, $items);
            },
            onItemError: function (Throwable $err) use ($self): void {
                $self->emitError($err);
            },
        );
        $this->batch = $batch;

        if ($this->opts->flushOnShutdown && !$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                if (!$this->disposed) {
                    try {
                        $this->flush();
                    } catch (Throwable) {
                        // never throw from a shutdown handler
                    }
                }
            });
        }

        return $batch;
    }

    private function assertOpen(): void
    {
        if ($this->disposed) {
            throw new LogDBError('LogDBClient has been disposed');
        }
    }

    // ── Field defaults ──────────────────────────────────────────

    private function normaliseLog(Log $log): Log
    {
        return $log->with([
            'apiKey' => $log->apiKey ?? $this->opts->apiKey,
            'collection' => $log->collection ?? $this->opts->defaultCollection,
            'application' => $log->application ?? $this->opts->defaultApplication ?? '',
            'environment' => $log->environment ?? $this->opts->defaultEnvironment,
            'level' => $log->level ?? LogLevel::Info,
            'guid' => $log->guid ?? self::randomUuid(),
            'timestamp' => $log->timestamp ?? new DateTimeImmutable(),
        ]);
    }

    private function normaliseBeat(LogBeat $beat): LogBeat
    {
        return $beat->with([
            'apiKey' => $beat->apiKey ?? $this->opts->apiKey,
            'collection' => $beat->collection ?? $this->opts->defaultCollection,
            'environment' => $beat->environment ?? $this->opts->defaultEnvironment,
            'guid' => $beat->guid ?? self::randomUuid(),
            'timestamp' => $beat->timestamp ?? new DateTimeImmutable(),
        ]);
    }

    private function normaliseCache(LogCache $cache): LogCache
    {
        return $cache->with([
            'apiKey' => $cache->apiKey ?? $this->opts->apiKey,
            'guid' => $cache->guid ?? self::randomUuid(),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function buildLogFromPsr(LogLevel $level, string $message, array $context): Log
    {
        $log = new Log(message: $this->interpolate($message, $context), level: $level);

        // PSR-3 convention: $context['exception'] holds a Throwable.
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            /** @var Throwable $e */
            $e = $context['exception'];
            $log->exception = $e::class . ': ' . $e->getMessage();
            $log->stackTrace = $e->getTraceAsString();
            unset($context['exception']);
        }

        // Pull out well-known shaped fields, route the rest into typed attribute maps.
        $stringMap = [];
        $numberMap = [];
        $boolMap = [];
        $dateMap = [];

        foreach ($context as $k => $v) {
            $ks = (string) $k;
            switch ($ks) {
                case 'user_id':
                case 'userId':
                    if (is_int($v)) {
                        $log->userId = $v;
                    }
                    break;
                case 'user_email':
                case 'userEmail':
                    if (is_string($v) || $v instanceof Stringable) {
                        $log->userEmail = (string) $v;
                    }
                    break;
                case 'correlation_id':
                case 'correlationId':
                    if (is_string($v) || $v instanceof Stringable) {
                        $log->correlationId = (string) $v;
                    }
                    break;
                case 'http.method':
                case 'http_method':
                    if (is_string($v) || $v instanceof Stringable) {
                        $log->httpMethod = (string) $v;
                    }
                    break;
                case 'http.target':
                case 'request_path':
                case 'requestPath':
                    if (is_string($v) || $v instanceof Stringable) {
                        $log->requestPath = (string) $v;
                    }
                    break;
                case 'http.status_code':
                case 'status_code':
                case 'statusCode':
                    if (is_int($v)) {
                        $log->statusCode = $v;
                    }
                    break;
                case 'client.address':
                case 'ip_address':
                case 'ipAddress':
                    if (is_string($v) || $v instanceof Stringable) {
                        $log->ipAddress = (string) $v;
                    }
                    break;
                default:
                    if (is_int($v) || is_float($v)) {
                        $numberMap[$ks] = $v;
                    } elseif (is_bool($v)) {
                        $boolMap[$ks] = $v;
                    } elseif ($v instanceof DateTimeImmutable) {
                        $dateMap[$ks] = $v;
                    } elseif (is_string($v) || $v instanceof Stringable) {
                        $stringMap[$ks] = (string) $v;
                    } elseif ($v !== null) {
                        $encoded = json_encode($v);
                        if ($encoded !== false) {
                            $stringMap[$ks] = $encoded;
                        }
                    }
            }
        }

        if ($stringMap !== []) {
            $log->attributesS = $stringMap;
        }
        if ($numberMap !== []) {
            $log->attributesN = $numberMap;
        }
        if ($boolMap !== []) {
            $log->attributesB = $boolMap;
        }
        if ($dateMap !== []) {
            $log->attributesD = $dateMap;
        }

        return $log;
    }

    /** PSR-3 `{placeholder}` interpolation. */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v) || $v === null || $v instanceof Stringable) {
                $replace['{' . $k . '}'] = (string) ($v ?? '');
            }
        }
        return strtr($message, $replace);
    }

    private static function classifyStatus(Throwable $err): LogResponseStatus
    {
        return match (true) {
            $err instanceof LogDBAuthError => LogResponseStatus::NotAuthorized,
            $err instanceof LogDBCircuitOpenError => LogResponseStatus::CircuitOpen,
            $err instanceof LogDBTimeoutError => LogResponseStatus::Timeout,
            default => LogResponseStatus::Failed,
        };
    }

    /**
     * Map PSR-3 string levels (and our LogLevel enum) → internal LogLevel.
     *
     * @param mixed $level
     */
    private static function psrLevelToLogLevel(mixed $level): LogLevel
    {
        if ($level instanceof LogLevel) {
            return $level;
        }
        $s = is_string($level) ? strtolower($level) : '';
        return match ($s) {
            PsrLogLevel::DEBUG => LogLevel::Debug,
            PsrLogLevel::INFO, PsrLogLevel::NOTICE => LogLevel::Info,
            PsrLogLevel::WARNING => LogLevel::Warning,
            PsrLogLevel::ERROR => LogLevel::Error,
            PsrLogLevel::CRITICAL, PsrLogLevel::ALERT, PsrLogLevel::EMERGENCY => LogLevel::Critical,
            default => LogLevel::Info,
        };
    }

    private static function randomUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant 10
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function __destruct()
    {
        if (!$this->disposed) {
            try {
                $this->dispose();
            } catch (Throwable) {
                // never throw from a destructor
            }
        }
    }
}
