<?php

declare(strict_types=1);

namespace LogDB\Models;

/**
 * Key/value cache entry. Sent through the OTLP logs pipeline with a
 * `_logdb.kind=cache` attribute so the server routes it to the cache table.
 *
 * `ttlSeconds` is reserved for future server-side TTL support and currently ignored.
 */
final class LogCache
{
    public function __construct(
        public string $key,
        public string $value,
        public ?string $guid = null,
        public ?string $apiKey = null,
        public ?int $ttlSeconds = null,
    ) {
    }

    public function with(array $patch): self
    {
        $clone = clone $this;
        foreach ($patch as $k => $v) {
            $clone->{$k} = $v;
        }
        return $clone;
    }
}
