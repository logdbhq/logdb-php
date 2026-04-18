<?php

declare(strict_types=1);

namespace LogDB\Tests\Support;

use LogDB\Errors\LogDBNetworkError;
use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Transport\TransportInterface;

/**
 * Test double that records every send call so assertions can inspect what the
 * SDK pushed downstream. Optionally fails specific batch types.
 *
 * @phpstan-type Batch array{type: string, items: array<int, Log|LogBeat|LogCache>}
 */
final class RecordingTransport implements TransportInterface
{
    /** @var list<Batch> */
    public array $batches = [];

    public int $singleSendCount = 0;
    public bool $closed = false;

    /** @var list<string> */
    public array $failBatchTypes = [];

    /** @var list<string> */
    public array $failSingleTypes = [];

    public function sendLog(Log $log): void
    {
        $this->singleSendCount++;
        if (in_array('log', $this->failSingleTypes, true)) {
            throw new LogDBNetworkError('forced single failure');
        }
    }

    public function sendLogBatch(array $logs): void
    {
        if (in_array('log', $this->failBatchTypes, true)) {
            throw new LogDBNetworkError('forced batch failure');
        }
        $this->batches[] = ['type' => 'log', 'items' => $logs];
    }

    public function sendLogBeat(LogBeat $beat): void
    {
        $this->singleSendCount++;
        if (in_array('logBeat', $this->failSingleTypes, true)) {
            throw new LogDBNetworkError('forced single failure');
        }
    }

    public function sendLogBeatBatch(array $beats): void
    {
        if (in_array('logBeat', $this->failBatchTypes, true)) {
            throw new LogDBNetworkError('forced batch failure');
        }
        $this->batches[] = ['type' => 'logBeat', 'items' => $beats];
    }

    public function sendLogCache(LogCache $cache): void
    {
        $this->singleSendCount++;
        if (in_array('logCache', $this->failSingleTypes, true)) {
            throw new LogDBNetworkError('forced single failure');
        }
    }

    public function sendLogCacheBatch(array $caches): void
    {
        if (in_array('logCache', $this->failBatchTypes, true)) {
            throw new LogDBNetworkError('forced batch failure');
        }
        $this->batches[] = ['type' => 'logCache', 'items' => $caches];
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
