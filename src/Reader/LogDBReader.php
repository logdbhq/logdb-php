<?php

declare(strict_types=1);

namespace LogDB\Reader;

use LogDB\Models\Reader\EventLogStatus;
use LogDB\Models\Reader\LogBeatEntry;
use LogDB\Models\Reader\LogBeatQueryParams;
use LogDB\Models\Reader\LogCacheEntry;
use LogDB\Models\Reader\LogCacheQueryParams;
use LogDB\Models\Reader\LogEntry;
use LogDB\Models\Reader\LogPage;
use LogDB\Models\Reader\LogQueryParams;
use Throwable;

/**
 * Read-side companion to `LogDBClient`. Queries the LogDB REST SDK API
 * (`/rest-api/log/sdk/...`) for logs, cache entries, heartbeats,
 * collections, and feature flags. Mirrors the same surface as
 * `LogDBReader` in `@logdbhq/node` and `@logdbhq/web`.
 *
 * Auth: `X-LogDB-ApiKey` header on every request, populated from
 * `LogDBReaderOptions->apiKey`.
 *
 * Lifecycle: stateless apart from a persistent curl handle inside
 * `ReaderTransport`. Construct once at app startup, reuse across
 * requests, call `dispose()` (or rely on the destructor) to release
 * the handle.
 */
final class LogDBReader
{
    private const PATH_LOGS = '/log/sdk/event/query';
    private const PATH_CACHE = '/log/sdk/cache/query';
    private const PATH_BEATS = '/log/sdk/beat/query';
    private const PATH_COLLECTIONS = '/log/sdk/distinct-values/collection';
    private const PATH_EVENT_LOG_STATUS = '/log/sdk/event-log-status';

    private readonly LogDBReaderOptions $opts;
    private readonly ReaderTransport $transport;
    private bool $disposed = false;

    public function __construct(LogDBReaderOptions $options, ?ReaderTransport $transport = null)
    {
        $this->opts = $options;
        $this->transport = $transport ?? new ReaderTransport($options);
    }

    /**
     * Query log events. Returns a paged result whose items are `LogEntry`.
     *
     * @return LogPage<LogEntry>
     */
    public function getLogs(?LogQueryParams $params = null): LogPage
    {
        $params ??= new LogQueryParams();
        $raw = $this->postJson(self::PATH_LOGS, $params->toJson());
        return LogPage::fromJson($raw, [LogEntry::class, 'fromJson']);
    }

    /**
     * Cheap count-only variant — same filters as `getLogs()` but skips
     * row materialisation. Useful for "how many errors today?" headers
     * without paying for a full page.
     */
    public function getLogsCount(?LogQueryParams $params = null): int
    {
        $params ??= new LogQueryParams();
        $params = clone $params;
        $params->take = 1; // server still computes totalCount on the same query
        $raw = $this->postJson(self::PATH_LOGS, $params->toJson());
        return (int) ($raw['totalCount'] ?? 0);
    }

    /**
     * @return LogPage<LogCacheEntry>
     */
    public function getLogCaches(?LogCacheQueryParams $params = null): LogPage
    {
        $params ??= new LogCacheQueryParams();
        $raw = $this->postJson(self::PATH_CACHE, $params->toJson());
        return LogPage::fromJson($raw, [LogCacheEntry::class, 'fromJson']);
    }

    /**
     * @return LogPage<LogBeatEntry>
     */
    public function getLogBeats(?LogBeatQueryParams $params = null): LogPage
    {
        $params ??= new LogBeatQueryParams();
        $raw = $this->postJson(self::PATH_BEATS, $params->toJson());
        return LogPage::fromJson($raw, [LogBeatEntry::class, 'fromJson']);
    }

    /**
     * Distinct values for the `collection` column — i.e. the set of
     * collections seen by this account so far. Useful to populate UI
     * collection pickers without a full scan.
     *
     * @return list<string>
     */
    public function getCollections(): array
    {
        $raw = $this->getJson(self::PATH_COLLECTIONS);
        $out = [];
        foreach ((array) $raw as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Per-account feature flags. Tells you whether Windows Events / IIS
     * Events / Windows Metrics ingestion is wired up. Mirrors the .NET
     * TUI's tab-visibility logic.
     */
    public function getEventLogStatus(): EventLogStatus
    {
        $raw = $this->getJson(self::PATH_EVENT_LOG_STATUS);
        // The endpoint always returns an object envelope; defensive narrow
        // for the case the API ever changes.
        if (!self::isAssoc($raw)) {
            return new EventLogStatus(false, false, false);
        }
        /** @var array<string, mixed> $raw */
        return EventLogStatus::fromJson($raw);
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        $this->transport->close();
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

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $this->assertOpen();
        try {
            $raw = $this->transport->postJson($path, $body);
        } catch (Throwable $e) {
            $this->emitError($e, $path);
            throw $e;
        }
        // postJson always returns a JSON object for our endpoints
        if (!self::isAssoc($raw)) {
            return [];
        }
        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * @param array<string, string|int|null> $query
     * @return array<string, mixed>|list<mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        $this->assertOpen();
        try {
            return $this->transport->getJson($path, $query);
        } catch (Throwable $e) {
            $this->emitError($e, $path);
            throw $e;
        }
    }

    private function emitError(Throwable $err, string $path): void
    {
        if ($this->opts->onError !== null) {
            ($this->opts->onError)($err, $path);
        }
    }

    private function assertOpen(): void
    {
        if ($this->disposed) {
            throw new \RuntimeException('LogDBReader has been disposed');
        }
    }

    /** @param array<string, mixed>|list<mixed> $arr */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true; // treat empty as object-shaped for our consumers
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
