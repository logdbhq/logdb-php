<?php

declare(strict_types=1);

namespace LogDB\Integrations\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogDB\Integrations\Monolog\LogDBHandler;
use LogDB\LogDBClient;
use LogDB\LogDBClientLike;
use LogDB\Options\LogDBClientOptions;

/**
 * Laravel service provider for the LogDB SDK.
 *
 * Auto-discovered via composer.json `extra.laravel.providers`. After install:
 *
 *   1. Publish the config:    php artisan vendor:publish --tag=logdb-config
 *   2. Set LOGDB_API_KEY in your .env
 *   3. Add a logging channel in config/logging.php:
 *
 *      'logdb' => [
 *          'driver' => 'monolog',
 *          'handler' => \LogDB\Integrations\Monolog\LogDBHandler::class,
 *          'with' => ['client' => app(\LogDB\LogDBClient::class)],
 *      ],
 *
 *   4. Use it: Log::channel('logdb')->info('hello');
 */
final class LogDBServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/logdb.php', 'logdb');

        $this->app->singleton(LogDBClient::class, static function (Container $app): LogDBClient {
            $config = $app->make('config')->get('logdb');

            $options = new LogDBClientOptions(
                endpoint: $config['endpoint'],
                apiKey: $config['api_key'] ?? null,
                defaultApplication: $config['application'] ?? null,
                defaultEnvironment: $config['environment'] ?? 'production',
                defaultCollection: $config['collection'] ?? 'logs',
                enableBatching: (bool) ($config['enable_batching'] ?? true),
                batchSize: (int) ($config['batch_size'] ?? 100),
                flushInterval: (int) ($config['flush_interval_ms'] ?? 5_000),
                maxRetries: (int) ($config['max_retries'] ?? 3),
                retryDelay: (int) ($config['retry_delay_ms'] ?? 1_000),
                enableCircuitBreaker: (bool) ($config['enable_circuit_breaker'] ?? true),
                requestTimeout: (int) ($config['request_timeout_ms'] ?? 30_000),
            );

            return new LogDBClient($options);
        });

        $this->app->alias(LogDBClient::class, LogDBClientLike::class);
        $this->app->alias(LogDBClient::class, 'logdb');

        // Convenience: a default-configured Monolog handler bound in the container
        // so users can pass `'handler' => app(LogDBHandler::class)` from the channel
        // config without manually wiring the client.
        $this->app->singleton(LogDBHandler::class, static function (Container $app): LogDBHandler {
            return new LogDBHandler($app->make(LogDBClient::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/logdb.php' => $this->configPath('logdb.php'),
            ], 'logdb-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [LogDBClient::class, LogDBClientLike::class, LogDBHandler::class, 'logdb'];
    }

    private function configPath(string $file): string
    {
        $base = function_exists('config_path') ? config_path() : $this->app->basePath('config');
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $file;
    }
}
