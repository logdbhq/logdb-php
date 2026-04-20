<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

use DateTimeImmutable;
use LogDB\Models\LogLevel;

/**
 * One log row as returned by `POST /rest-api/log/sdk/event/query`.
 *
 * Mirrors the .NET `SdkLogDto` shape (camelCase JSON keys). Differs from
 * the writer-side `LogDB\Models\Log` in two ways: it has every field
 * always-set (no null-as-default), and it uses immutable `readonly`
 * properties — these are query results, not things the user constructs.
 */
final class LogEntry
{
    /**
     * @param string[]                       $labels
     * @param array<string, string>          $attributesS
     * @param array<string, int|float>       $attributesN
     * @param array<string, bool>            $attributesB
     * @param array<string, DateTimeImmutable> $attributesD
     */
    public function __construct(
        public readonly string $id,
        public readonly string $guid,
        public readonly DateTimeImmutable $timestamp,
        public readonly string $application,
        public readonly string $environment,
        public readonly LogLevel $level,
        public readonly string $message,
        public readonly string $exception,
        public readonly string $stackTrace,
        public readonly string $source,
        public readonly int $userId,
        public readonly string $userEmail,
        public readonly string $correlationId,
        public readonly string $requestPath,
        public readonly string $httpMethod,
        public readonly string $additionalData,
        public readonly string $ipAddress,
        public readonly int $statusCode,
        public readonly string $description,
        public readonly string $collection,
        public readonly DateTimeImmutable $dateIn,
        public readonly array $labels,
        public readonly array $attributesS,
        public readonly array $attributesN,
        public readonly array $attributesB,
        public readonly array $attributesD,
        public readonly ?string $aiAnalysisId = null,
        public readonly ?string $aiInsights = null,
        public readonly ?string $aiRecommendations = null,
        public readonly ?float $aiConfidenceScore = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromJson(array $raw): self
    {
        return new self(
            id: (string) ($raw['id'] ?? ''),
            guid: (string) ($raw['guid'] ?? ''),
            timestamp: self::parseDate($raw['timestamp'] ?? null),
            application: (string) ($raw['application'] ?? ''),
            environment: (string) ($raw['environment'] ?? ''),
            level: self::parseLevel($raw['level'] ?? null),
            message: (string) ($raw['message'] ?? ''),
            exception: (string) ($raw['exception'] ?? ''),
            stackTrace: (string) ($raw['stackTrace'] ?? ''),
            source: (string) ($raw['source'] ?? ''),
            userId: (int) ($raw['userId'] ?? 0),
            userEmail: (string) ($raw['userEmail'] ?? ''),
            correlationId: (string) ($raw['correlationId'] ?? ''),
            requestPath: (string) ($raw['requestPath'] ?? ''),
            httpMethod: (string) ($raw['httpMethod'] ?? ''),
            additionalData: (string) ($raw['additionalData'] ?? ''),
            ipAddress: (string) ($raw['ipAddress'] ?? ''),
            statusCode: (int) ($raw['statusCode'] ?? 0),
            description: (string) ($raw['description'] ?? ''),
            collection: (string) ($raw['collection'] ?? ''),
            dateIn: self::parseDate($raw['dateIn'] ?? null),
            labels: array_map('strval', (array) ($raw['labels'] ?? [])),
            attributesS: self::stringMap($raw['attributeS'] ?? []),
            attributesN: self::numberMap($raw['attributeN'] ?? []),
            attributesB: self::boolMap($raw['attributeB'] ?? []),
            attributesD: self::dateMap($raw['attributeD'] ?? []),
            aiAnalysisId: isset($raw['aiAnalysisId']) ? (string) $raw['aiAnalysisId'] : null,
            aiInsights: isset($raw['aiInsights']) ? (string) $raw['aiInsights'] : null,
            aiRecommendations: isset($raw['aiRecommendations']) ? (string) $raw['aiRecommendations'] : null,
            aiConfidenceScore: isset($raw['aiConfidenceScore']) ? (float) $raw['aiConfidenceScore'] : null,
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

    private static function parseLevel(mixed $raw): LogLevel
    {
        if (is_string($raw)) {
            foreach (LogLevel::cases() as $case) {
                if (strcasecmp($case->name, $raw) === 0) {
                    return $case;
                }
            }
        }
        if (is_int($raw)) {
            $found = LogLevel::tryFrom($raw);
            if ($found !== null) {
                return $found;
            }
        }
        return LogLevel::Info;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        return $out;
    }

    /** @return array<string, int|float> */
    private static function numberMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_int($v) || is_float($v)) {
                $out[(string) $k] = $v;
            } elseif (is_numeric($v)) {
                $out[(string) $k] = str_contains((string) $v, '.') ? (float) $v : (int) $v;
            }
        }
        return $out;
    }

    /** @return array<string, bool> */
    private static function boolMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = (bool) $v;
        }
        return $out;
    }

    /** @return array<string, DateTimeImmutable> */
    private static function dateMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = self::parseDate($v);
        }
        return $out;
    }
}
