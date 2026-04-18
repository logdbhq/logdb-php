<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Batching;

use LogDB\Batching\BatchEngine;
use LogDB\Errors\LogDBNetworkError;
use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class BatchEngineTest extends TestCase
{
    public function testEnqueueBuffersUntilBatchSizeReached(): void
    {
        $transport = new RecordingTransport();
        $engine = new BatchEngine($transport, batchSize: 3, flushIntervalMs: 60_000);

        $engine->enqueueLog(new Log(message: 'a'));
        $engine->enqueueLog(new Log(message: 'b'));
        $this->assertCount(0, $transport->batches, 'should not flush before batchSize reached');

        $engine->enqueueLog(new Log(message: 'c'));
        $this->assertCount(1, $transport->batches);
        $this->assertCount(3, $transport->batches[0]['items']);
    }

    public function testFlushDrainsAllTypes(): void
    {
        $transport = new RecordingTransport();
        $engine = new BatchEngine($transport, batchSize: 100, flushIntervalMs: 60_000);

        $engine->enqueueLog(new Log(message: 'l'));
        $engine->enqueueLogBeat(new LogBeat(measurement: 'm'));
        $engine->enqueueLogCache(new LogCache(key: 'k', value: 'v'));

        $this->assertSame(3, $engine->totalSize());
        $engine->flush();
        $this->assertSame(0, $engine->totalSize());

        $types = array_map(static fn (array $b) => $b['type'], $transport->batches);
        sort($types);
        $this->assertSame(['log', 'logBeat', 'logCache'], $types);
    }

    public function testBatchFailureFallsBackToPerItemSends(): void
    {
        $transport = new RecordingTransport();
        $transport->failBatchTypes = ['log'];

        $batchErrors = [];
        $itemErrors = [];

        $engine = new BatchEngine(
            transport: $transport,
            batchSize: 2,
            flushIntervalMs: 60_000,
            onBatchError: function (\Throwable $err, string $type, array $items) use (&$batchErrors): void {
                $batchErrors[] = ['type' => $type, 'count' => count($items), 'err' => $err::class];
            },
            onItemError: function (\Throwable $err) use (&$itemErrors): void {
                $itemErrors[] = $err::class;
            },
        );

        $engine->enqueueLog(new Log(message: 'a'));
        $engine->enqueueLog(new Log(message: 'b'));

        $this->assertCount(1, $batchErrors);
        $this->assertSame('log', $batchErrors[0]['type']);
        $this->assertSame(LogDBNetworkError::class, $batchErrors[0]['err']);

        // 2 per-item retries fired (transport always fails this type).
        $this->assertSame(2, $transport->singleSendCount);
        $this->assertCount(2, $itemErrors);
    }

    public function testDisposeFlushes(): void
    {
        $transport = new RecordingTransport();
        $engine = new BatchEngine($transport, batchSize: 100, flushIntervalMs: 60_000);

        $engine->enqueueLog(new Log(message: 'a'));
        $engine->enqueueLog(new Log(message: 'b'));
        $engine->dispose();

        $this->assertCount(1, $transport->batches);
        $this->assertCount(2, $transport->batches[0]['items']);
    }
}
