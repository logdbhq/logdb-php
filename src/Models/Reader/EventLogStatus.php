<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

/**
 * Feature-availability flags returned by `GET /rest-api/log/sdk/event-log-status`.
 *
 * Tells the caller whether the account has Windows Events / IIS Events /
 * Windows Metrics ingestion enabled. Use to gate UI tabs the same way the
 * .NET TUI does.
 */
final class EventLogStatus
{
    public function __construct(
        public readonly bool $hasWindowsEvents,
        public readonly bool $hasIISEvents,
        public readonly bool $hasWindowsMetrics = false,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromJson(array $raw): self
    {
        return new self(
            hasWindowsEvents: (bool) ($raw['hasWindowsEvents'] ?? false),
            hasIISEvents: (bool) ($raw['hasIISEvents'] ?? false),
            hasWindowsMetrics: (bool) ($raw['hasWindowsMetrics'] ?? false),
        );
    }
}
