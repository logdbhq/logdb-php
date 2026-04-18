<?php

declare(strict_types=1);

namespace LogDB\Builders;

use DateTimeImmutable;
use LogDB\LogDBClientLike;
use LogDB\Models\LogBeat;
use LogDB\Models\LogMeta;
use LogDB\Models\LogResponseStatus;

/** Fluent, immutable builder for `LogBeat` (heartbeat / periodic measurement) entries. */
final class LogBeatBuilder
{
    private function __construct(
        private readonly LogBeat $entry,
        private readonly LogDBClientLike $client,
    ) {
    }

    public static function create(LogDBClientLike $client): self
    {
        return new self(new LogBeat(measurement: ''), $client);
    }

    public function build(): LogBeat
    {
        return $this->entry;
    }

    public function setMeasurement(string $measurement): self
    {
        return $this->with(['measurement' => $measurement]);
    }

    public function setCollection(string $collection): self
    {
        return $this->with(['collection' => $collection]);
    }

    public function setEnvironment(string $environment): self
    {
        return $this->with(['environment' => $environment]);
    }

    public function setTimestamp(DateTimeImmutable $timestamp): self
    {
        return $this->with(['timestamp' => $timestamp]);
    }

    public function setGuid(string $guid): self
    {
        return $this->with(['guid' => $guid]);
    }

    public function addTag(string $key, string $value): self
    {
        $next = $this->entry->tag ?? [];
        $next[] = new LogMeta($key, $value);
        return $this->with(['tag' => $next]);
    }

    public function addField(string $key, string|int|float|bool $value): self
    {
        $next = $this->entry->field ?? [];
        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $next[] = new LogMeta($key, $stringValue);
        return $this->with(['field' => $next]);
    }

    public function log(): LogResponseStatus
    {
        return $this->client->logBeat($this->entry);
    }

    /** @param array<string, mixed> $patch */
    private function with(array $patch): self
    {
        return new self($this->entry->with($patch), $this->client);
    }
}
