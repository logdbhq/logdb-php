<?php

declare(strict_types=1);

namespace LogDB\Transport;

use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;

/** Transport-layer abstraction so we can swap implementations / stub in tests. */
interface TransportInterface
{
    public function sendLog(Log $log): void;

    /** @param Log[] $logs */
    public function sendLogBatch(array $logs): void;

    public function sendLogBeat(LogBeat $beat): void;

    /** @param LogBeat[] $beats */
    public function sendLogBeatBatch(array $beats): void;

    public function sendLogCache(LogCache $cache): void;

    /** @param LogCache[] $caches */
    public function sendLogCacheBatch(array $caches): void;

    /** Release any underlying resources. */
    public function close(): void;
}
