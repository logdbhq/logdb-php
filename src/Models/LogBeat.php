<?php

declare(strict_types=1);

namespace LogDB\Models;

use DateTimeImmutable;

/** Heartbeat / periodic measurement. Maps onto OTLP metrics on the wire. */
final class LogBeat
{
    /**
     * @param LogMeta[]|null $tag
     * @param LogMeta[]|null $field
     */
    public function __construct(
        public string $measurement,
        public ?array $tag = null,
        public ?array $field = null,
        public ?DateTimeImmutable $timestamp = null,
        public ?string $collection = null,
        public ?string $environment = null,
        public ?string $guid = null,
        public ?string $apiKey = null,
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
