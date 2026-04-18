<?php

declare(strict_types=1);

namespace LogDB\Builders;

use LogDB\LogDBClientLike;
use LogDB\Models\LogCache;
use LogDB\Models\LogResponseStatus;

/** Fluent, immutable builder for `LogCache` entries. */
final class LogCacheBuilder
{
    private function __construct(
        private readonly LogCache $entry,
        private readonly LogDBClientLike $client,
    ) {
    }

    public static function create(LogDBClientLike $client): self
    {
        return new self(new LogCache(key: '', value: ''), $client);
    }

    public function build(): LogCache
    {
        return $this->entry;
    }

    public function setKey(string $key): self
    {
        return $this->with(['key' => $key]);
    }

    /** @param string|int|float|bool|array<mixed>|object $value */
    public function setValue(string|int|float|bool|array|object $value): self
    {
        $stringified = match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        };
        return $this->with(['value' => $stringified]);
    }

    public function setGuid(string $guid): self
    {
        return $this->with(['guid' => $guid]);
    }

    public function setTtlSeconds(int $ttlSeconds): self
    {
        return $this->with(['ttlSeconds' => $ttlSeconds]);
    }

    public function log(): LogResponseStatus
    {
        return $this->client->logCache($this->entry);
    }

    /** @param array<string, mixed> $patch */
    private function with(array $patch): self
    {
        return new self($this->entry->with($patch), $this->client);
    }
}
