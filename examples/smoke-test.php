<?php

declare(strict_types=1);

/**
 * Rigorous smoke test — disables batching, surfaces every error and status.
 *
 *   LOGDB_API_KEY=... LOGDB_ENDPOINT=https://test-01.logdb.site/otlp \
 *     php examples/smoke-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use LogDB\Builders\LogBeatBuilder;
use LogDB\Builders\LogEventBuilder;
use LogDB\LogDBClient;
use LogDB\Models\LogLevel;
use LogDB\Models\LogResponseStatus;
use LogDB\Options\LogDBClientOptions;

$apiKey = getenv('LOGDB_API_KEY') ?: '';
$endpoint = getenv('LOGDB_ENDPOINT') ?: 'https://otlp.logdb.site';
if ($apiKey === '') {
    fwrite(STDERR, "Set LOGDB_API_KEY in env.\n");
    exit(1);
}

$errors = [];
$client = new LogDBClient(new LogDBClientOptions(
    endpoint: $endpoint,
    apiKey: $apiKey,
    defaultApplication: 'logdb-php-smoke',
    defaultEnvironment: 'smoke-test',
    enableBatching: false,                  // every call hits the wire immediately
    enableCircuitBreaker: false,
    flushOnShutdown: false,
    onError: function (\Throwable $e) use (&$errors): void {
        $errors[] = $e::class . ': ' . $e->getMessage();
    },
));

echo "endpoint:    {$endpoint}\n";
echo "application: logdb-php-smoke\n";
echo "----\n";

$probe = bin2hex(random_bytes(4));   // unique tag so you can find this run in LogDB

$s1 = $client->info("PSR-3 path probe={$probe}", [
    'user_email' => 'alice@example.com',
    'correlation_id' => "trace-{$probe}",
    'tenant' => 'acme',
    'amount_eur' => 199.99,
    'verified' => true,
]);
echo "PSR-3 info():    " . ($s1 === null ? 'void (PSR-3 returns nothing)' : '') . "\n";

$s2 = LogEventBuilder::create($client)
    ->setMessage("builder path probe={$probe}")
    ->setLogLevel(LogLevel::Info)
    ->setUserEmail('bob@example.com')
    ->setCorrelationId("trace-{$probe}")
    ->addAttribute('amount_eur', 49.50)
    ->addAttribute('currency', 'EUR')
    ->addLabel('payment')
    ->log();
echo "LogEventBuilder: " . statusName($s2) . "\n";

$s3 = LogBeatBuilder::create($client)
    ->setMeasurement('smoke.heartbeat')
    ->addTag('probe', $probe)
    ->addField('value', 1)
    ->log();
echo "LogBeatBuilder:  " . statusName($s3) . "\n";

$client->dispose();

echo "----\n";
if ($errors === []) {
    echo "no SDK errors. probe={$probe}\n";
    exit(0);
}

echo "SDK errors:\n";
foreach ($errors as $e) {
    echo "  - {$e}\n";
}
exit(1);

function statusName(LogResponseStatus $s): string
{
    return $s->value;
}
