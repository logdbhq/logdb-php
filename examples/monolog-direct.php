<?php

declare(strict_types=1);

/**
 * Standalone Monolog setup. Drops the LogDB handler into any Monolog channel —
 * useful when you're not on Laravel/Symfony but want PSR-3 + Monolog ergonomics.
 *
 * Requires `composer require monolog/monolog`.
 *
 *   LOGDB_API_KEY=your-key php examples/monolog-direct.php
 */

require __DIR__ . '/../vendor/autoload.php';

use LogDB\Integrations\Monolog\LogDBHandler;
use LogDB\LogDBClient;
use LogDB\Options\LogDBClientOptions;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$apiKey = getenv('LOGDB_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Set LOGDB_API_KEY in your environment.\n");
    exit(1);
}

$logdb = new LogDBClient(new LogDBClientOptions(
    endpoint: getenv('LOGDB_ENDPOINT') ?: 'https://otlp.logdb.site',
    apiKey: $apiKey,
    defaultApplication: 'monolog-example',
    defaultEnvironment: 'development',
));

$logger = new Logger('app');
// Tee output: ship to LogDB and also print to stderr for local visibility.
$logger->pushHandler(new LogDBHandler($logdb, level: Level::Info));
$logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));

$logger->info('checkout started', [
    'user_email' => 'alice@example.com',
    'correlation_id' => 'trace-monolog-001',
    'cart_size' => 3,
]);

try {
    throw new \RuntimeException('payment gateway returned 500');
} catch (\Throwable $e) {
    $logger->error('checkout failed', [
        'exception' => $e,
        'user_email' => 'alice@example.com',
        'correlation_id' => 'trace-monolog-001',
    ]);
}

$logdb->dispose();
echo "Sent.\n";
