<?php

declare(strict_types=1);

namespace LogDB\Batching;

use Closure;
use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Transport\TransportInterface;
use Throwable;

/**
 * Type-aware synchronous batching pipeline.
 *
 * PHP-specific design notes:
 *   - PHP has no event loop, so flush triggers are: buffer full, explicit flush(),
 *     or `LogDBClient` registering `register_shutdown_function`.
 *   - The `flushInterval` is checked on each enqueue against the timestamp of the
 *     buffer's oldest entry — for long-lived workers (Octane, RoadRunner, queue
 *     workers) this gives time-bounded flushes between shutdown.
 */
final class BatchEngine
{
    public const TYPE_LOG = 'log';
    public const TYPE_LOG_BEAT = 'logBeat';
    public const TYPE_LOG_CACHE = 'logCache';

    /** @var list<Log> */
    private array $logs = [];
    /** @var list<LogBeat> */
    private array $beats = [];
    /** @var list<LogCache> */
    private array $caches = [];

    /** Wall-clock timestamp (ms) of the oldest entry in any buffer, or 0 if empty. */
    private float $oldestEnqueuedMs = 0.0;

    private bool $disposed = false;

    /** @var (Closure(Throwable, string, list<Log>|list<LogBeat>|list<LogCache>): void)|null */
    private $onBatchError;

    /** @var (Closure(Throwable, string, Log|LogBeat|LogCache): void)|null */
    private $onItemError;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $batchSize,
        private readonly int $flushIntervalMs,
        ?Closure $onBatchError = null,
        ?Closure $onItemError = null,
    ) {
        $this->onBatchError = $onBatchError;
        $this->onItemError = $onItemError;
    }

    public function enqueueLog(Log $log): void
    {
        $this->beforeEnqueue();
        $this->logs[] = $log;
        $this->maybeFlush(count($this->logs));
    }

    public function enqueueLogBeat(LogBeat $beat): void
    {
        $this->beforeEnqueue();
        $this->beats[] = $beat;
        $this->maybeFlush(count($this->beats));
    }

    public function enqueueLogCache(LogCache $cache): void
    {
        $this->beforeEnqueue();
        $this->caches[] = $cache;
        $this->maybeFlush(count($this->caches));
    }

    public function flush(): void
    {
        $logs = $this->logs;
        $beats = $this->beats;
        $caches = $this->caches;
        $this->logs = [];
        $this->beats = [];
        $this->caches = [];
        $this->oldestEnqueuedMs = 0.0;

        if ($logs !== []) {
            $this->flushLogs($logs);
        }
        if ($beats !== []) {
            $this->flushBeats($beats);
        }
        if ($caches !== []) {
            $this->flushCaches($caches);
        }
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        $this->flush();
    }

    public function totalSize(): int
    {
        return count($this->logs) + count($this->beats) + count($this->caches);
    }

    // ── internals ───────────────────────────────────────────────

    private function beforeEnqueue(): void
    {
        if ($this->disposed) {
            throw new \RuntimeException('BatchEngine is disposed');
        }
        if ($this->oldestEnqueuedMs === 0.0) {
            $this->oldestEnqueuedMs = microtime(true) * 1000.0;
        }
    }

    private function maybeFlush(int $bufferSize): void
    {
        $elapsedMs = (microtime(true) * 1000.0) - $this->oldestEnqueuedMs;
        if ($bufferSize >= $this->batchSize || $elapsedMs >= $this->flushIntervalMs) {
            $this->flush();
        }
    }

    /** @param list<Log> $items */
    private function flushLogs(array $items): void
    {
        try {
            $this->transport->sendLogBatch($items);
            return;
        } catch (Throwable $batchErr) {
            if ($this->onBatchError !== null) {
                ($this->onBatchError)($batchErr, self::TYPE_LOG, $items);
            }
            foreach ($items as $item) {
                try {
                    $this->transport->sendLog($item);
                } catch (Throwable $itemErr) {
                    if ($this->onItemError !== null) {
                        ($this->onItemError)($itemErr, self::TYPE_LOG, $item);
                    }
                }
            }
        }
    }

    /** @param list<LogBeat> $items */
    private function flushBeats(array $items): void
    {
        try {
            $this->transport->sendLogBeatBatch($items);
            return;
        } catch (Throwable $batchErr) {
            if ($this->onBatchError !== null) {
                ($this->onBatchError)($batchErr, self::TYPE_LOG_BEAT, $items);
            }
            foreach ($items as $item) {
                try {
                    $this->transport->sendLogBeat($item);
                } catch (Throwable $itemErr) {
                    if ($this->onItemError !== null) {
                        ($this->onItemError)($itemErr, self::TYPE_LOG_BEAT, $item);
                    }
                }
            }
        }
    }

    /** @param list<LogCache> $items */
    private function flushCaches(array $items): void
    {
        try {
            $this->transport->sendLogCacheBatch($items);
            return;
        } catch (Throwable $batchErr) {
            if ($this->onBatchError !== null) {
                ($this->onBatchError)($batchErr, self::TYPE_LOG_CACHE, $items);
            }
            foreach ($items as $item) {
                try {
                    $this->transport->sendLogCache($item);
                } catch (Throwable $itemErr) {
                    if ($this->onItemError !== null) {
                        ($this->onItemError)($itemErr, self::TYPE_LOG_CACHE, $item);
                    }
                }
            }
        }
    }
}
