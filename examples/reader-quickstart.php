<?php

declare(strict_types=1);

/**
 * Reader quickstart — query the logs you've already sent.
 *
 *   LOGDB_API_KEY=your-key \
 *   LOGDB_READER_ENDPOINT=https://test-01.logdb.site/rest-api \
 *     php examples/reader-quickstart.php [application]
 *
 * Defaults to filtering by application = "logdb-php-sample" so you'll
 * find rows sent by the writer-side examples in this repo.
 */

require __DIR__ . '/../vendor/autoload.php';

use LogDB\Models\LogLevel;
use LogDB\Models\Reader\LogQueryParams;
use LogDB\Reader\LogDBReader;
use LogDB\Reader\LogDBReaderOptions;

$apiKey = getenv('LOGDB_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Set LOGDB_API_KEY in your environment.\n");
    exit(1);
}

$application = $argv[1] ?? 'logdb-php-sample';

$reader = new LogDBReader(new LogDBReaderOptions(
    endpoint: getenv('LOGDB_READER_ENDPOINT') ?: 'https://test-01.logdb.site/rest-api',
    apiKey: $apiKey,
    requestTimeout: 10_000,
));

echo 'endpoint:    ' . (getenv('LOGDB_READER_ENDPOINT') ?: 'https://test-01.logdb.site/rest-api') . "\n";
echo "application: {$application}\n";
echo "----\n";

// Feature flags first — proves the API key works AND tells us which
// optional tabs (Windows / IIS / Metrics) the account has data for.
$status = $reader->getEventLogStatus();
echo 'event-log-status: windows=' . ($status->hasWindowsEvents ? 'yes' : 'no')
    . ' iis=' . ($status->hasIISEvents ? 'yes' : 'no') . "\n";

// Collections seen by this account (= distinct values of the `collection`
// column across all logs). Useful for UIs that need to show a picker.
$collections = $reader->getCollections();
echo 'collections (' . count($collections) . '): ' . implode(', ', $collections) . "\n";

// And the actual logs.
$page = $reader->getLogs(new LogQueryParams(
    application: $application,
    take: 5,
));

echo "\nlast {$page->pageSize} of {$page->totalCount} logs for application={$application}:\n";
foreach ($page->items as $log) {
    $ts = $log->timestamp->format('Y-m-d H:i:s');
    $level = str_pad($log->level->toString(), 7);
    $message = strlen($log->message) > 60 ? substr($log->message, 0, 59) . '…' : $log->message;
    echo "  [{$ts}] {$level} {$message}\n";
}

if ($page->hasMore) {
    echo "\n  → " . ($page->totalCount - count($page->items)) . " more — paginate with skip += take.\n";
}

// Cheap count for "how many error-level entries today?"
$errors = $reader->getLogsCount(new LogQueryParams(
    application: $application,
    level: LogLevel::Error,
    fromDate: new DateTimeImmutable('-1 day'),
));
echo "\nerror-level in last 24h: {$errors}\n";

$reader->dispose();
