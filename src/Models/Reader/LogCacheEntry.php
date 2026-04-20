<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

use DateTimeImmutable;

final class LogCacheEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $guid,
        public readonly string $key,
        public readonly string $value,
        public readonly string $collection,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?int $ttlSeconds = null,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromJson(array $raw): self
    {
        return new self(
            id: (string) ($raw['id'] ?? ''),
            guid: (string) ($raw['guid'] ?? ''),
            key: (string) ($raw['key'] ?? ''),
            value: (string) ($raw['value'] ?? ''),
            collection: (string) ($raw['collection'] ?? ''),
            createdAt: self::parseDate($raw['createdAt'] ?? $raw['dateIn'] ?? null),
            ttlSeconds: isset($raw['ttlSeconds']) ? (int) $raw['ttlSeconds'] : null,
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
}
