<?php

declare(strict_types=1);

/**
 * Minimal standalone PHP usage. Run with:
 *   LOGDB_API_KEY=your-key php examples/standalone.php
 */

require __DIR__ . '/../vendor/autoload.php';

use LogDB\Builders\LogBeatBuilder;
use LogDB\Builders\LogEventBuilder;
use LogDB\LogDBClient;
use LogDB\Models\LogLevel;
use LogDB\Options\LogDBClientOptions;

$apiKey = getenv('LOGDB_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Set LOGDB_API_KEY in your environment.\n");
    exit(1);
}

$client = new LogDBClient(new LogDBClientOptions(
    endpoint: getenv('LOGDB_ENDPOINT') ?: 'https://otlp.logdb.site',
    apiKey: $apiKey,
    defaultApplication: 'standalone-example',
    defaultEnvironment: 'development',
));

// ── Direct PSR-3 API ─────────────────────────────────────────────────────────
$client->info('user logged in', [
    'user_email' => 'alice@example.com',
    'correlation_id' => 'trace-abc-123',
    'tenant' => 'acme',
    'amount_eur' => 199.99,
    'verified' => true,
]);

// ── Builder API ──────────────────────────────────────────────────────────────
LogEventBuilder::create($client)
    ->setMessage('payment processed')
    ->setLogLevel(LogLevel::Info)
    ->setUserEmail('alice@example.com')
    ->setCorrelationId('trace-abc-123')
    ->addAttribute('amount_eur', 199.99)
    ->addAttribute('currency', 'EUR')
    ->addAttribute('verified', true)
    ->addLabel('payment')
    ->addLabel('checkout')
    ->log();

// ── Heartbeat / measurement ──────────────────────────────────────────────────
LogBeatBuilder::create($client)
    ->setMeasurement('queue.depth')
    ->addTag('queue', 'orders.confirmed')
    ->addField('depth', 1247)
    ->log();

// Drain the buffer + close any underlying resources.
$client->dispose();

echo "Sent. Check LogDB.\n";
