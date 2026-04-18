<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Transport;

use DateTimeImmutable;
use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Models\LogLevel;
use LogDB\Models\LogMeta;
use LogDB\Transport\OtlpMappers;
use PHPUnit\Framework\TestCase;

final class OtlpMappersTest extends TestCase
{
    public function testBuildsLogsRequestWithResourceAttributes(): void
    {
        $log = new Log(message: 'hi', level: LogLevel::Info, application: 'app', environment: 'prod');
        $req = OtlpMappers::buildLogsRequest([$log], [
            'apiKey' => 'k',
            'application' => 'fallback',
            'environment' => 'fallback-env',
            'collection' => 'logs',
        ]);

        $this->assertCount(1, $req['resourceLogs']);
        $resourceAttrs = $req['resourceLogs'][0]['resource']['attributes'];
        $kv = self::flatten($resourceAttrs);

        $this->assertSame('app', $kv['service.name']);
        $this->assertSame('prod', $kv['deployment.environment']);
        $this->assertSame('k', $kv['logdb.apikey']);
        $this->assertSame('logs', $kv['logdb.collection']);
    }

    public function testGroupsLogsByApplicationEnvironmentCollection(): void
    {
        $logs = [
            new Log(message: 'a', application: 'app1', environment: 'prod'),
            new Log(message: 'b', application: 'app2', environment: 'prod'),
            new Log(message: 'c', application: 'app1', environment: 'prod'),
        ];
        $req = OtlpMappers::buildLogsRequest($logs, []);
        $this->assertCount(2, $req['resourceLogs']);
    }

    public function testLogRecordIncludesSeverityBodyAndAttributes(): void
    {
        $log = new Log(
            message: 'payment processed',
            timestamp: new DateTimeImmutable('@1700000000'),
            level: LogLevel::Warning,
            userEmail: 'alice@example.com',
            correlationId: 'trace-1',
            statusCode: 200,
            label: ['payment', 'critical'],
            attributesS: ['currency' => 'EUR'],
            attributesN: ['amount' => 199.99],
            attributesB: ['verified' => true],
        );
        $req = OtlpMappers::buildLogsRequest([$log], []);
        $record = $req['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        $this->assertSame(13, $record['severityNumber']);
        $this->assertSame('Warning', $record['severityText']);
        $this->assertSame('payment processed', $record['body']['stringValue']);
        $this->assertSame('1700000000000000000', $record['timeUnixNano']);

        $kv = self::flatten($record['attributes']);
        $this->assertSame('alice@example.com', $kv['user.email']);
        $this->assertSame('trace-1', $kv['correlation_id']);
        $this->assertSame('200', $kv['http.status_code']);
        $this->assertSame('payment', $kv['logdb.label.0']);
        $this->assertSame('critical', $kv['logdb.label.1']);
        $this->assertSame('EUR', $kv['currency']);
        $this->assertSame(199.99, $kv['amount']);
        $this->assertTrue($kv['verified']);
    }

    public function testBuildMetricsRequestEmitsGauge(): void
    {
        $beat = new LogBeat(
            measurement: 'queue.depth',
            tag: [new LogMeta('queue', 'orders')],
            field: [new LogMeta('depth', '1247')],
        );
        $req = OtlpMappers::buildMetricsRequest([$beat], []);

        $metric = $req['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];
        $this->assertSame('queue.depth', $metric['name']);
        $this->assertCount(1, $metric['gauge']['dataPoints']);
        $point = $metric['gauge']['dataPoints'][0];
        $this->assertEqualsWithDelta(1247.0, $point['asDouble'], 0.0001);

        $attrs = self::flatten($point['attributes']);
        $this->assertSame('orders', $attrs['queue']);
        $this->assertSame('depth', $attrs['field.name']);
    }

    public function testBuildCacheRequestRoutesViaLogsPipeline(): void
    {
        $cache = new LogCache(key: 'user:1', value: 'alice');
        $req = OtlpMappers::buildCacheRequest([$cache], []);

        $record = $req['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        $this->assertSame('alice', $record['body']['stringValue']);

        $attrs = self::flatten($record['attributes']);
        $this->assertSame('cache', $attrs['_logdb.kind']);
        $this->assertSame('user:1', $attrs['_logdb.cache.key']);
    }

    /**
     * @param array<int, array{key: string, value: array<string, mixed>}> $attrs
     * @return array<string, mixed>
     */
    private static function flatten(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $a) {
            $v = $a['value'];
            $out[$a['key']] = $v['stringValue'] ?? $v['intValue'] ?? $v['doubleValue'] ?? $v['boolValue'] ?? null;
        }
        return $out;
    }
}
