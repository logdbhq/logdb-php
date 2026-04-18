# Changelog

All notable changes to `logdbhq/logdb-php` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/logdbhq/logdb-php/compare/v0.1.0-alpha.0...HEAD
[0.1.0-alpha.0]: https://github.com/logdbhq/logdb-php/releases/tag/v0.1.0-alpha.0
