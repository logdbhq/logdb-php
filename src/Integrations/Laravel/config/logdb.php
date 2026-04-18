<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | LogDB API key
    |--------------------------------------------------------------------------
    | The API key your LogDB account issued. Required for direct ingestion.
    */
    'api_key' => env('LOGDB_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | OTLP endpoint
    |--------------------------------------------------------------------------
    | The base URL the SDK will POST to. The SDK appends /v1/logs and
    | /v1/metrics. Use the LogDB collector for direct mode, or your own relay
    | URL if you're keeping the API key off the client.
    */
    'endpoint' => env('LOGDB_ENDPOINT', 'https://otlp.logdb.site'),

    /*
    |--------------------------------------------------------------------------
    | Identity defaults
    |--------------------------------------------------------------------------
    */
    'application' => env('LOGDB_APPLICATION', env('APP_NAME', 'laravel')),
    'environment' => env('LOGDB_ENVIRONMENT', env('APP_ENV', 'production')),
    'collection' => env('LOGDB_COLLECTION', 'logs'),

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    */
    'enable_batching' => env('LOGDB_ENABLE_BATCHING', true),
    'batch_size' => (int) env('LOGDB_BATCH_SIZE', 100),
    'flush_interval_ms' => (int) env('LOGDB_FLUSH_INTERVAL_MS', 5_000),

    /*
    |--------------------------------------------------------------------------
    | Resilience
    |--------------------------------------------------------------------------
    */
    'max_retries' => (int) env('LOGDB_MAX_RETRIES', 3),
    'retry_delay_ms' => (int) env('LOGDB_RETRY_DELAY_MS', 1_000),
    'request_timeout_ms' => (int) env('LOGDB_REQUEST_TIMEOUT_MS', 30_000),
    'enable_circuit_breaker' => env('LOGDB_ENABLE_CIRCUIT_BREAKER', true),
];
