<?php

declare(strict_types=1);

namespace LogDB\Tests\Support;

use LogDB\LogDBClientLike;
use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Models\LogResponseStatus;

/**
 * Test double for `LogDBClientLike` that records every entry without making any
 * HTTP calls. Used by builder and integration tests.
 */
final class InMemoryClient implements LogDBClientLike
{
    /** @var list<Log> */
    public array $logs = [];

    /** @var list<LogBeat> */
    public array $beats = [];

    /** @var list<LogCache> */
    public array $caches = [];

    public bool $flushed = false;
    public bool $disposed = false;

    public function logEntry(Log $log): LogResponseStatus
    {
        $this->logs[] = $log;
        return LogResponseStatus::Success;
    }

    public function sendLogBatch(array $logs): LogResponseStatus
    {
        foreach ($logs as $l) {
            $this->logs[] = $l;
        }
        return LogResponseStatus::Success;
    }

    public function logBeat(LogBeat $beat): LogResponseStatus
    {
        $this->beats[] = $beat;
        return LogResponseStatus::Success;
    }

    public function sendLogBeatBatch(array $beats): LogResponseStatus
    {
        foreach ($beats as $b) {
            $this->beats[] = $b;
        }
        return LogResponseStatus::Success;
    }

    public function logCache(LogCache $cache): LogResponseStatus
    {
        $this->caches[] = $cache;
        return LogResponseStatus::Success;
    }

    public function sendLogCacheBatch(array $caches): LogResponseStatus
    {
        foreach ($caches as $c) {
            $this->caches[] = $c;
        }
        return LogResponseStatus::Success;
    }

    public function flush(): void
    {
        $this->flushed = true;
    }

    public function dispose(): void
    {
        $this->disposed = true;
    }
}
