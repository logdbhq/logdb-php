# Changelog

All notable changes to `logdbhq/logdb-php` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0-alpha.0] — 2026-04-20

### Added
- **Reader / Query API.** New `LogDB\Reader\LogDBReader` brings PHP to feature
  parity with `@logdbhq/node` and `@logdbhq/web` on the read side. Hits the
  LogDB SDK REST API (`/rest-api/log/sdk/...`) directly via curl — no relay,
  no gRPC-Web, no extra dependencies.
- Public methods (mirror Node 1:1):
  - `getLogs(?LogQueryParams)` → `LogPage<LogEntry>`
  - `getLogCaches(?LogCacheQueryParams)` → `LogPage<LogCacheEntry>`
  - `getLogBeats(?LogBeatQueryParams)` → `LogPage<LogBeatEntry>`
  - `getCollections()` → `string[]`
  - `getLogsCount(?LogQueryParams)` → `int` (cheap count-only variant)
  - `getEventLogStatus()` → `EventLogStatus` (feature flags)
- New types under `LogDB\Models\Reader\`: `LogQueryParams`,
  `LogCacheQueryParams`, `LogBeatQueryParams`, `LogPage`, `LogEntry`,
  `LogCacheEntry`, `LogBeatEntry`, `EventLogStatus`.
- New `LogDB\Reader\LogDBReaderOptions` + `ReaderTransport`. Transport reuses
  the same `RetryPolicy` and typed-error model as the writer side.
- Auth: `X-LogDB-ApiKey` header on every request.
- `examples/reader-quickstart.php` — end-to-end demo: feature flags +
  collections + recent logs + count-by-level.
- 7 reader unit tests via injectable sender (envelope parsing, attribute
  maps, date round-trip, level enum mapping, header injection, error mapping).

### Notes
- Wire format: request bodies use camelCase JSON. Date filters serialise
  to `Y-m-d\TH:i:s.u\Z`. `LogLevel` accepted as enum or string.
- Pagination: offset-based (`skip`/`take`). `LogPage->hasMore` indicates
  whether another page exists.
- 56 PHPUnit tests, 176 assertions. PHPStan level 8 clean.

## [0.1.0-alpha.0] — 2026-04-18

### Added
- Initial release. PSR-3 compliant `LogDBClient` shipping over OTLP HTTP/JSON.
- `LogEventBuilder`, `LogBeatBuilder`, `LogCacheBuilder` fluent immutable builders.
- `OtlpHttpTransport` using `ext-curl` directly. No Guzzle / PSR-18 required.
- `BatchEngine` with per-type buffers and `register_shutdown_function` flush.
- `RetryPolicy` with HTTP-status-aware retry (429 / 5xx retried; 400 / 401 / 403
  surface immediately as typed errors).
- `CircuitBreaker` (failure-rate sliding-window, mirrors Polly).
- Monolog handler (`LogDB\Integrations\Monolog\LogDBHandler`).
- Laravel service provider (auto-discovered) + publishable config.
- Examples: standalone, Laravel controller sketch, direct Monolog.
- PHP 8.1+, 8.2, 8.3 supported via CI matrix.

[Unreleased]: https://github.com/logdbhq/logdb-php/compare/v0.2.0-alpha.0...HEAD
[0.2.0-alpha.0]: https://github.com/logdbhq/logdb-php/releases/tag/v0.2.0-alpha.0
[0.1.0-alpha.0]: https://github.com/logdbhq/logdb-php/releases/tag/v0.1.0-alpha.0
