<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Transport;

use LogDB\Errors\LogDBAuthError;
use LogDB\Errors\LogDBConfigError;
use LogDB\Errors\LogDBNetworkError;
use LogDB\Models\Log;
use LogDB\Transport\OtlpHttpTransport;
use PHPUnit\Framework\TestCase;

final class OtlpHttpTransportTest extends TestCase
{
    public function testSuccessfulPostReturnsWithoutThrowing(): void
    {
        $captured = null;
        $sender = function (string $url, string $body, array $headers, int $timeout) use (&$captured): array {
            $captured = ['url' => $url, 'body' => $body, 'headers' => $headers, 'timeout' => $timeout];
            return [200, ''];
        };

        $transport = new OtlpHttpTransport(
            endpoint: 'https://otlp.logdb.site',
            apiKey: 'k',
            defaultApplication: 'app',
            defaultEnvironment: 'prod',
            defaultCollection: 'logs',
            requestTimeoutMs: 5_000,
            sender: $sender,
        );

        $transport->sendLog(new Log(message: 'hi'));

        $this->assertNotNull($captured);
        $this->assertSame('https://otlp.logdb.site/v1/logs', $captured['url']);
        $this->assertSame('application/json', $captured['headers']['content-type']);
        $this->assertStringContainsString('"resourceLogs"', $captured['body']);
        $this->assertStringContainsString('"logdb.apikey"', $captured['body']);
    }

    public function testStatus401MapsToAuthError(): void
    {
        $sender = static fn () => [401, 'unauthorized'];
        $transport = $this->makeTransport($sender);

        $this->expectException(LogDBAuthError::class);
        $transport->sendLog(new Log(message: 'x'));
    }

    public function testStatus403MapsToAuthError(): void
    {
        $transport = $this->makeTransport(static fn () => [403, '']);
        $this->expectException(LogDBAuthError::class);
        $transport->sendLog(new Log(message: 'x'));
    }

    public function testStatus400MapsToConfigError(): void
    {
        $transport = $this->makeTransport(static fn () => [400, 'malformed']);
        $this->expectException(LogDBConfigError::class);
        $transport->sendLog(new Log(message: 'x'));
    }

    public function testStatus500MapsToNetworkError(): void
    {
        $transport = $this->makeTransport(static fn () => [500, 'oops']);
        $this->expectException(LogDBNetworkError::class);
        $transport->sendLog(new Log(message: 'x'));
    }

    public function testStatus429MapsToNetworkError(): void
    {
        $transport = $this->makeTransport(static fn () => [429, '']);
        $this->expectException(LogDBNetworkError::class);
        $transport->sendLog(new Log(message: 'x'));
    }

    public function testEmptyBatchIsNoop(): void
    {
        $called = false;
        $transport = $this->makeTransport(function () use (&$called): array {
            $called = true;
            return [200, ''];
        });

        $transport->sendLogBatch([]);
        $transport->sendLogBeatBatch([]);
        $transport->sendLogCacheBatch([]);

        $this->assertFalse($called);
    }

    public function testTrailingSlashStrippedFromEndpoint(): void
    {
        $captured = null;
        $sender = function (string $url) use (&$captured): array {
            $captured = $url;
            return [200, ''];
        };
        $transport = new OtlpHttpTransport(
            endpoint: 'https://otlp.logdb.site/',
            apiKey: 'k',
            defaultApplication: null,
            defaultEnvironment: null,
            defaultCollection: null,
            requestTimeoutMs: 1_000,
            sender: $sender,
        );

        $transport->sendLog(new Log(message: 'x'));
        $this->assertSame('https://otlp.logdb.site/v1/logs', $captured);
    }

    private function makeTransport(callable $sender): OtlpHttpTransport
    {
        return new OtlpHttpTransport(
            endpoint: 'https://otlp.logdb.site',
            apiKey: 'k',
            defaultApplication: null,
            defaultEnvironment: null,
            defaultCollection: null,
            requestTimeoutMs: 1_000,
            sender: $sender,
        );
    }
}
