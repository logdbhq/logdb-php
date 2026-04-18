# logdbhq/logdb-php

[![Packagist Version](https://img.shields.io/packagist/v/logdbhq/logdb-php?include_prereleases&color=blue)](https://packagist.org/packages/logdbhq/logdb-php)
[![PHP](https://img.shields.io/packagist/php-v/logdbhq/logdb-php?color=8892bf)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Universal LogDB SDK for PHP. Ships logs, heartbeats, and cache entries to LogDB
over OTLP HTTP/JSON. Drops into any PHP runtime that has `ext-curl` —
Laravel, Symfony, WordPress, queue workers, plain PHP scripts.

- **PSR-3 first** — `LogDBClient` implements `Psr\Log\LoggerInterface`. Plug into
  anything that speaks PSR-3.
- **Monolog handler included** — one line to ship every Monolog record into LogDB.
- **Laravel service provider auto-discovered** — `composer require` and you're done.
- **Zero hard dependencies** beyond `psr/log`. Uses `ext-curl` directly. No Guzzle,
  no Symfony HttpClient, no protobuf compiler.
- **Same API as `@logdbhq/node` / `@logdbhq/web` / `LogDB.SDK` (.NET)** — mixed-stack
  apps look identical on every side.

> **Status:** alpha. The wire format and resilience defaults are stable; the public
> API may still tighten in 0.x.

## Install

```bash
composer require logdbhq/logdb-php:^0.1@alpha
```

PHP **8.1+** required. `ext-curl` and `ext-json` must be available.

## 30-second start

```php
use LogDB\LogDBClient;
use LogDB\Options\LogDBClientOptions;

$client = new LogDBClient(new LogDBClientOptions(
    endpoint: 'https://otlp.logdb.site',
    apiKey: getenv('LOGDB_API_KEY'),
    defaultApplication: 'my-service',
    defaultEnvironment: 'production',
));

// PSR-3 ergonomics
$client->info('user logged in', [
    'user_email' => 'alice@example.com',
    'correlation_id' => 'trace-abc-123',
    'tenant' => 'acme',
]);

// Errors with throwable context
try {
    chargeCard($cart);
} catch (\Throwable $e) {
    $client->error('payment failed', ['exception' => $e]);
}

// Drain on shutdown is automatic; or call explicitly:
$client->dispose();
```

## Laravel

The service provider is auto-discovered. Set the API key in `.env`:

```
LOGDB_API_KEY=your-key-here
LOGDB_ENDPOINT=https://otlp.logdb.site
LOGDB_APPLICATION=my-laravel-app
```

Add a logging channel to `config/logging.php`:

```php
'logdb' => [
    'driver' => 'monolog',
    'handler' => \LogDB\Integrations\Monolog\LogDBHandler::class,
],
```

Use it anywhere:

```php
use Illuminate\Support\Facades\Log;

Log::channel('logdb')->info('user signed up', [
    'user_email' => $user->email,
    'plan' => $user->plan,
]);
```

(Optional) publish the config to tweak batching / retry / circuit-breaker settings:

```bash
php artisan vendor:publish --tag=logdb-config
```

## Monolog (standalone)

```php
use LogDB\Integrations\Monolog\LogDBHandler;
use Monolog\Logger;

$logger = new Logger('app');
$logger->pushHandler(new LogDBHandler($logdbClient));

$logger->error('order failed', ['exception' => $e, 'order_id' => 42]);
```

Monolog levels map to LogDB levels:
| Monolog                          | LogDB        |
|----------------------------------|--------------|
| `Debug`                          | `Debug`      |
| `Info`, `Notice`                 | `Info`       |
| `Warning`                        | `Warning`    |
| `Error`                          | `Error`      |
| `Critical`, `Alert`, `Emergency` | `Critical`   |

## Builder API

For full-fidelity sends (typed attribute maps, labels, structured HTTP fields):

```php
use LogDB\Builders\LogEventBuilder;
use LogDB\Models\LogLevel;

LogEventBuilder::create($client)
    ->setMessage('checkout completed')
    ->setLogLevel(LogLevel::Info)
    ->setUserEmail('alice@example.com')
    ->setCorrelationId($traceId)
    ->setRequestPath('/api/checkout')
    ->setHttpMethod('POST')
    ->setStatusCode(200)
    ->addAttribute('amount_eur', 199.99)         // → attributesN
    ->addAttribute('currency', 'EUR')            // → attributesS
    ->addAttribute('verified', true)             // → attributesB
    ->addAttribute('completed_at', new DateTimeImmutable())   // → attributesD
    ->addLabel('payment')
    ->addLabel('checkout')
    ->log();
```

Heartbeats / measurements:

```php
use LogDB\Builders\LogBeatBuilder;

LogBeatBuilder::create($client)
    ->setMeasurement('queue.depth')
    ->addTag('queue', 'orders.confirmed')
    ->addField('depth', 1247)
    ->log();
```

Key/value cache writes:

```php
use LogDB\Builders\LogCacheBuilder;

LogCacheBuilder::create($client)
    ->setKey('user:42:profile')
    ->setValue(['name' => 'Alice', 'plan' => 'pro'])
    ->log();
```

## Configuration

Every option has a sensible default. Pass them by name:

```php
new LogDBClientOptions(
    endpoint: 'https://otlp.logdb.site',
    apiKey: getenv('LOGDB_API_KEY'),
    defaultApplication: 'checkout-service',
    defaultEnvironment: 'production',
    defaultCollection: 'logs',

    // batching
    enableBatching: true,
    batchSize: 100,
    flushInterval: 5_000,           // ms

    // retry
    maxRetries: 3,
    retryDelay: 1_000,              // ms
    retryBackoffMultiplier: 2.0,

    // circuit breaker
    enableCircuitBreaker: true,
    circuitBreakerFailureThreshold: 0.5,
    circuitBreakerSamplingDuration: 10_000,
    circuitBreakerDurationOfBreak: 30_000,

    // transport
    requestTimeout: 30_000,         // ms
    headers: ['x-team' => 'platform'],

    // diagnostics
    enableDebugLogging: false,
    onError: fn (\Throwable $e, ?array $batch) => error_log("logdb: {$e->getMessage()}"),

    // PHP-specific: drain pending logs at request shutdown
    flushOnShutdown: true,
)
```

## Error model

Sends never throw on transient failures — they retry internally and surface a
status code. For total visibility, register an `onError` callback or read the
return value:

```php
use LogDB\Models\LogResponseStatus;

$status = $client->logEntry($log);

match ($status) {
    LogResponseStatus::Success => null,
    LogResponseStatus::NotAuthorized => throw new \RuntimeException('Bad LogDB key'),
    LogResponseStatus::CircuitOpen => $metrics->increment('logdb.circuit_open'),
    LogResponseStatus::Timeout, LogResponseStatus::Failed => $metrics->increment('logdb.failed'),
};
```

Typed exceptions (all extend `LogDB\Errors\LogDBError`):

| Class                   | Thrown when                                   |
|-------------------------|-----------------------------------------------|
| `LogDBAuthError`        | HTTP 401 / 403. Not retried.                  |
| `LogDBConfigError`      | HTTP 400 / invalid endpoint URL. Not retried. |
| `LogDBNetworkError`     | 5xx, 429, network failure after retries.      |
| `LogDBTimeoutError`     | Request exceeded `requestTimeout`.            |
| `LogDBCircuitOpenError` | Circuit breaker rejected without sending.     |

## Lifecycle

The constructor does **no I/O** — the curl handle and OTLP transport open lazily
on the first send.

`dispose()` flushes the batch buffer and closes the curl handle. The destructor
calls `dispose()` automatically. By default, the client also registers a
`register_shutdown_function` callback that flushes pending entries before the
PHP request ends — disable with `flushOnShutdown: false` if you want full control.

## Sibling SDKs

Same API surface across the LogDB SDK family:

- **`LogDB.SDK`** (.NET) — native gRPC, the reference implementation
- **`@logdbhq/node`** — Node.js, native gRPC
- **`@logdbhq/web`** — universal JS/TS over OTLP HTTP/JSON
- **`logdbhq/logdb-php`** — this package

## Documentation

Full docs: https://docs.logdb.dev

## License

[MIT](LICENSE) © LogDB
