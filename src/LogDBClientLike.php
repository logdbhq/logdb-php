<?php

declare(strict_types=1);

namespace LogDB;

use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Models\LogResponseStatus;

/**
 * Interface for the LogDB client. Builders accept this so consumers can swap in
 * a fake client in tests without depending on the concrete class.
 */
interface LogDBClientLike
{
    public function logEntry(Log $log): LogResponseStatus;

    /** @param Log[] $logs */
    public function sendLogBatch(array $logs): LogResponseStatus;

    public function logBeat(LogBeat $beat): LogResponseStatus;

    /** @param LogBeat[] $beats */
    public function sendLogBeatBatch(array $beats): LogResponseStatus;

    public function logCache(LogCache $cache): LogResponseStatus;

    /** @param LogCache[] $caches */
    public function sendLogCacheBatch(array $caches): LogResponseStatus;

    public function flush(): void;

    public function dispose(): void;
}
