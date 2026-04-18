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

    /** @var array{log: list<Log>, logBeat: list<LogBeat>, logCache: list<LogCache>} */
    private array $buffers = ['log' => [], 'logBeat' => [], 'logCache' => []];

    /** Wall-clock timestamp (ms) of the oldest entry in any buffer, or 0 if empty. */
    private float $oldestEnqueuedMs = 0.0;

    private bool $disposed = false;

    /** @var (Closure(\Throwable, string, list<Log>|list<LogBeat>|list<LogCache>): void)|null */
    private $onBatchError;
    /** @var (Closure(\Throwable, string, Log|LogBeat|LogCache): void)|null */
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
        $this->enqueue(self::TYPE_LOG, $log);
    }

    public function enqueueLogBeat(LogBeat $beat): void
    {
        $this->enqueue(self::TYPE_LOG_BEAT, $beat);
    }

    public function enqueueLogCache(LogCache $cache): void
    {
        $this->enqueue(self::TYPE_LOG_CACHE, $cache);
    }

    public function flush(): void
    {
        $drained = $this->buffers;
        $this->buffers = ['log' => [], 'logBeat' => [], 'logCache' => []];
        $this->oldestEnqueuedMs = 0.0;

        foreach ([self::TYPE_LOG, self::TYPE_LOG_BEAT, self::TYPE_LOG_CACHE] as $type) {
            if ($drained[$type] !== []) {
                $this->sendBatchOrFallback($type, $drained[$type]);
            }
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
        return count($this->buffers['log'])
            + count($this->buffers['logBeat'])
            + count($this->buffers['logCache']);
    }

    private function enqueue(string $type, Log|LogBeat|LogCache $payload): void
    {
        if ($this->disposed) {
            throw new \RuntimeException('BatchEngine is disposed');
        }

        if ($this->oldestEnqueuedMs === 0.0) {
            $this->oldestEnqueuedMs = microtime(true) * 1000.0;
        }

        $this->buffers[$type][] = $payload;

        $bufferSize = count($this->buffers[$type]);
        $elapsedMs = (microtime(true) * 1000.0) - $this->oldestEnqueuedMs;

        if ($bufferSize >= $this->batchSize || $elapsedMs >= $this->flushIntervalMs) {
            $this->flush();
        }
    }

    /**
     * @param list<Log>|list<LogBeat>|list<LogCache> $items
     */
    private function sendBatchOrFallback(string $type, array $items): void
    {
        try {
            $this->sendBatch($type, $items);
            return;
        } catch (Throwable $batchErr) {
            if ($this->onBatchError !== null) {
                ($this->onBatchError)($batchErr, $type, $items);
            }

            // Per-item fallback so one bad payload can't poison the batch.
            foreach ($items as $item) {
                try {
                    $this->sendOne($type, $item);
                } catch (Throwable $itemErr) {
                    if ($this->onItemError !== null) {
                        ($this->onItemError)($itemErr, $type, $item);
                    }
                }
            }
        }
    }

    /**
     * @param list<Log>|list<LogBeat>|list<LogCache> $items
     */
    private function sendBatch(string $type, array $items): void
    {
        match ($type) {
            self::TYPE_LOG => $this->transport->sendLogBatch($items),
            self::TYPE_LOG_BEAT => $this->transport->sendLogBeatBatch($items),
            self::TYPE_LOG_CACHE => $this->transport->sendLogCacheBatch($items),
        };
    }

    private function sendOne(string $type, Log|LogBeat|LogCache $item): void
    {
        match ($type) {
            self::TYPE_LOG => $this->transport->sendLog($item),
            self::TYPE_LOG_BEAT => $this->transport->sendLogBeat($item),
            self::TYPE_LOG_CACHE => $this->transport->sendLogCache($item),
        };
    }
}
