<?php

declare(strict_types=1);

namespace LogDB\Tests\Integration;

use DateTimeImmutable;
use LogDB\LogDBClient;
use LogDB\Models\LogLevel;
use LogDB\Models\LogResponseStatus;
use LogDB\Options\LogDBClientOptions;
use LogDB\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogDBClientTest extends TestCase
{
    public function testPsrLogInfoBuildsLogWithDefaults(): void
    {
        $transport = new RecordingTransport();
        $client = new LogDBClient(
            $this->options(['enableBatching' => false]),
            $transport,
        );

        $client->info('user logged in', [
            'user_email' => 'alice@example.com',
            'correlation_id' => 'trace-1',
            'tenant' => 'acme',
            'amount_eur' => 199.99,
            'verified' => true,
        ]);

        $this->assertCount(1, $transport->batches);
        $items = $transport->batches[0]['items'];
        $this->assertCount(1, $items);

        $log = $items[0];
        $this->assertSame('user logged in', $log->message);
        $this->assertSame(LogLevel::Info, $log->level);
        $this->assertSame('test-app', $log->application);
        $this->assertSame('alice@example.com', $log->userEmail);
        $this->assertSame('trace-1', $log->correlationId);
        $this->assertSame(['tenant' => 'acme'], $log->attributesS);
        $this->assertSame(['amount_eur' => 199.99], $log->attributesN);
        $this->assertSame(['verified' => true], $log->attributesB);
        $this->assertNotNull($log->guid);
    }

    public function testPsrErrorWithExceptionPopulatesStackTrace(): void
    {
        $transport = new RecordingTransport();
        $client = new LogDBClient(
            $this->options(['enableBatching' => false]),
            $transport,
        );

        $err = new RuntimeException('boom');
        $client->error('handler failed', ['exception' => $err]);

        $log = $transport->batches[0]['items'][0];
        $this->assertSame(LogLevel::Error, $log->level);
        $this->assertNotNull($log->exception);
        $this->assertStringContainsString('boom', $log->exception);
        $this->assertNotNull($log->stackTrace);
    }

    public function testPsrInterpolation(): void
    {
        $transport = new RecordingTransport();
        $client = new LogDBClient(
            $this->options(['enableBatching' => false]),
            $transport,
        );

        $client->info('user {id} logged in', ['id' => 42]);
        $log = $transport->batches[0]['items'][0];
        $this->assertSame('user 42 logged in', $log->message);
    }

    public function testBatchingFlushesAtBatchSize(): void
    {
        $transport = new RecordingTransport();
        $client = new LogDBClient(
            $this->options(['enableBatching' => true, 'batchSize' => 3, 'flushInterval' => 60_000]),
            $transport,
        );

        $client->info('a');
        $client->info('b');
        $this->assertCount(0, $transport->batches);

        $client->info('c');
        $this->assertCount(1, $transport->batches);
        $this->assertCount(3, $transport->batches[0]['items']);
    }

    public function testFlushDrainsBuffer(): void
    {
        $transport = new RecordingTransport();
        $client = new LogDBClient(
            $this->options(['enableBatching' => true, 'batchSize' => 1_000, 'flushInterval' => 60_000]),
            $transport,
        );

        $client->info('a');
        $client->info('b');
        $this->assertCount(0, $transport->batches);

        $client->flush();
        $this->assertCount(1, $transport->batches);
        $this->assertCount(2, $transport->batches[0]['items']);
    }

    public function testDirectSendReturnsStatusOnAuthError(): void
    {
        $transport = new RecordingTransport();
        $transport->failBatchTypes = ['log'];
        $transport->failSingleTypes = ['log'];

        // Substitute a transport that throws an AuthError instead of generic network.
        $authTransport = new class () extends RecordingTransport {
            public function sendLog(\LogDB\Models\Log $log): void
            {
                throw new \LogDB\Errors\LogDBAuthError();
            }
        };

        $client = new LogDBClient(
            $this->options(['enableBatching' => false, 'maxRetries' => 0]),
            $authTransport,
        );
        $status = $client->logEntry(new \LogDB\Models\Log(message: 'x'));
        $this->assertSame(LogResponseStatus::NotAuthorized, $status);
    }

    public function testNormalisationAddsGuidAndTimestamp(): void
    {
        $transport = new RecordingTransport();
        $client = new LogDBClient(
            $this->options(['enableBatching' => false]),
            $transport,
        );

        $client->logEntry(new \LogDB\Models\Log(message: 'm'));
        $log = $transport->batches[0]['items'][0];

        $this->assertNotNull($log->guid);
        $this->assertNotNull($log->timestamp);
        $this->assertInstanceOf(DateTimeImmutable::class, $log->timestamp);
    }

    /** @param array<string, mixed> $overrides */
    private function options(array $overrides = []): LogDBClientOptions
    {
        $defaults = [
            'endpoint' => 'https://otlp.logdb.site',
            'apiKey' => 'test-key',
            'defaultApplication' => 'test-app',
            'defaultEnvironment' => 'test',
            'flushOnShutdown' => false,
        ];
        $merged = array_merge($defaults, $overrides);
        return new LogDBClientOptions(...$merged);
    }
}
