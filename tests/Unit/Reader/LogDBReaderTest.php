<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Reader;

use DateTimeImmutable;
use LogDB\Errors\LogDBAuthError;
use LogDB\Errors\LogDBConfigError;
use LogDB\Models\LogLevel;
use LogDB\Models\Reader\LogQueryParams;
use LogDB\Reader\LogDBReader;
use LogDB\Reader\LogDBReaderOptions;
use LogDB\Reader\ReaderTransport;
use PHPUnit\Framework\TestCase;

final class LogDBReaderTest extends TestCase
{
    public function testGetEventLogStatusParsesFlags(): void
    {
        $reader = $this->reader(static fn () => [200, '{"hasWindowsEvents":true,"hasIISEvents":false,"hasWindowsMetrics":true}']);
        $status = $reader->getEventLogStatus();

        $this->assertTrue($status->hasWindowsEvents);
        $this->assertFalse($status->hasIISEvents);
        $this->assertTrue($status->hasWindowsMetrics);
    }

    public function testGetCollectionsReturnsStringList(): void
    {
        $reader = $this->reader(static fn () => [200, '["logs","audit","ops"]']);
        $this->assertSame(['logs', 'audit', 'ops'], $reader->getCollections());
    }

    public function testGetLogsParsesPageEnvelope(): void
    {
        /** @var array{method: string, url: string, body: string, headers: array<string, string>}|null $captured */
        $captured = null;
        $sender = function (string $method, string $url, ?string $body, array $headers, int $timeout) use (&$captured): array {
            $captured = ['method' => $method, 'url' => $url, 'body' => $body ?? '', 'headers' => $headers];
            return [200, json_encode([
                'items' => [
                    [
                        'id' => '101',
                        'guid' => 'abc-123',
                        'timestamp' => '2026-04-20T11:18:53.577Z',
                        'application' => 'logdb-php-sample',
                        'environment' => 'production',
                        'level' => 'Error',
                        'message' => 'payment failed',
                        'attributeS' => ['currency' => 'EUR'],
                        'attributeN' => ['amount' => 199.99],
                        'attributeB' => ['fraud' => true],
                    ],
                ],
                'totalCount' => 1,
                'page' => 0,
                'pageSize' => 50,
                'hasMore' => false,
            ], JSON_THROW_ON_ERROR)];
        };

        $reader = $this->reader($sender);
        $page = $reader->getLogs(new LogQueryParams(application: 'logdb-php-sample'));

        $this->assertNotNull($captured);
        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/log/sdk/event/query', $captured['url']);
        $this->assertSame('test-key-7890', $captured['headers']['X-LogDB-ApiKey']);
        $body = json_decode($captured['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('logdb-php-sample', $body['application']);

        // envelope
        $this->assertSame(1, $page->totalCount);
        $this->assertCount(1, $page->items);

        // entry parsing
        $log = $page->items[0];
        $this->assertSame('payment failed', $log->message);
        $this->assertSame(LogLevel::Error, $log->level);
        $this->assertEqualsWithDelta(199.99, $log->attributesN['amount'], 0.001);
        $this->assertTrue($log->attributesB['fraud']);
    }

    public function testGetLogsCountTrimsTakeButReturnsTotal(): void
    {
        /** @var array<string, mixed>|null $captured */
        $captured = null;
        $sender = function (string $method, string $url, ?string $body) use (&$captured): array {
            $captured = is_string($body) ? json_decode($body, true) : null;
            return [200, '{"items":[],"totalCount":4172,"page":0,"pageSize":1,"hasMore":true}'];
        };

        $reader = $this->reader($sender);
        $count = $reader->getLogsCount(new LogQueryParams(level: LogLevel::Error, take: 50));

        $this->assertSame(4172, $count);
        $this->assertNotNull($captured);
        $this->assertSame(1, $captured['take'], 'count call should override take=1 to skip page materialisation');
        $this->assertSame('Error', $captured['level']);
    }

    public function testQueryParamsSerialiseDatesAsIso(): void
    {
        /** @var array<string, mixed>|null $captured */
        $captured = null;
        $sender = function (string $method, string $url, ?string $body) use (&$captured): array {
            $captured = is_string($body) ? json_decode($body, true) : null;
            return [200, '{"items":[],"totalCount":0,"page":0,"pageSize":50,"hasMore":false}'];
        };

        $from = new DateTimeImmutable('2026-04-20T08:00:00.000Z');
        $reader = $this->reader($sender);
        $reader->getLogs(new LogQueryParams(fromDate: $from));

        $this->assertNotNull($captured);
        $this->assertNotNull($captured['fromDate']);
        $this->assertStringStartsWith('2026-04-20T08:00:00', (string) $captured['fromDate']);
    }

    public function testAuthErrorOn401(): void
    {
        $reader = $this->reader(static fn () => [401, '{"error":"Invalid API key"}'], maxRetries: 0);
        $this->expectException(LogDBAuthError::class);
        $reader->getCollections();
    }

    public function testConfigErrorOn400(): void
    {
        $reader = $this->reader(static fn () => [400, '{"error":"bad query"}'], maxRetries: 0);
        $this->expectException(LogDBConfigError::class);
        $reader->getLogs();
    }

    private function reader(callable $sender, int $maxRetries = 0): LogDBReader
    {
        $opts = new LogDBReaderOptions(
            endpoint: 'https://example.test/rest-api',
            apiKey: 'test-key-7890',
            maxRetries: $maxRetries,
            retryDelay: 1,
            requestTimeout: 1_000,
        );
        return new LogDBReader($opts, new ReaderTransport($opts, $sender));
    }
}
