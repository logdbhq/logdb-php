<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

use DateTimeImmutable;

final class LogBeatEntry
{
    /**
     * @param array<string, string> $tags
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $guid,
        public readonly string $measurement,
        public readonly string $collection,
        public readonly DateTimeImmutable $timestamp,
        public readonly array $tags,
        public readonly array $fields,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromJson(array $raw): self
    {
        return new self(
            id: (string) ($raw['id'] ?? ''),
            guid: (string) ($raw['guid'] ?? ''),
            measurement: (string) ($raw['measurement'] ?? ''),
            collection: (string) ($raw['collection'] ?? ''),
            timestamp: self::parseDate($raw['timestamp'] ?? $raw['dateIn'] ?? null),
            tags: self::stringMap($raw['tags'] ?? $raw['tag'] ?? []),
            fields: self::stringMap($raw['fields'] ?? $raw['field'] ?? []),
        );
    }

    private static function parseDate(mixed $raw): DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return new DateTimeImmutable('@0');
        }
        try {
            return new DateTimeImmutable((string) $raw);
        } catch (\Throwable) {
            return new DateTimeImmutable('@0');
        }
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            // Both shapes seen in the wild: { key: value } and [{ key, value }]
            if (is_array($v) && isset($v['key'], $v['value'])) {
                $out[(string) $v['key']] = (string) $v['value'];
            } else {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }
}
