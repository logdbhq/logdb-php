<?php

declare(strict_types=1);

namespace LogDB\Models;

/** Generic key/value metadata pair, used for LogBeat tags and fields. */
final class LogMeta
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
    ) {
    }
}
