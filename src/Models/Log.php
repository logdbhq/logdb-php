<?php

declare(strict_types=1);

namespace LogDB\Models;

use DateTimeImmutable;

/**
 * The primary log entry sent to LogDB. Same shape as the .NET / Node / Web SDKs.
 * Every field except `message` is optional; defaults are filled by `LogDBClient`.
 *
 * @phpstan-type StringMap array<string, string>
 * @phpstan-type NumberMap array<string, int|float>
 * @phpstan-type BoolMap array<string, bool>
 * @phpstan-type DateMap array<string, DateTimeImmutable>
 */
final class Log
{
    /**
     * @param string[]|null         $label
     * @param StringMap|null        $attributesS
     * @param NumberMap|null        $attributesN
     * @param BoolMap|null          $attributesB
     * @param DateMap|null          $attributesD
     */
    public function __construct(
        public string $message,
        public ?DateTimeImmutable $timestamp = null,
        public ?LogLevel $level = null,
        public ?string $application = null,
        public ?string $environment = null,
        public ?string $collection = null,
        public ?string $exception = null,
        public ?string $stackTrace = null,
        public ?string $source = null,
        public ?int $userId = null,
        public ?string $userEmail = null,
        public ?string $correlationId = null,
        public ?string $httpMethod = null,
        public ?string $requestPath = null,
        public ?int $statusCode = null,
        public ?string $ipAddress = null,
        public ?string $additionalData = null,
        public ?string $description = null,
        public ?string $id = null,
        public ?string $guid = null,
        public ?array $label = null,
        public ?array $attributesS = null,
        public ?array $attributesN = null,
        public ?array $attributesB = null,
        public ?array $attributesD = null,
        public ?string $apiKey = null,
    ) {
    }

    /** Shallow copy with the given fields overridden. */
    public function with(array $patch): self
    {
        $clone = clone $this;
        foreach ($patch as $k => $v) {
            $clone->{$k} = $v;
        }
        return $clone;
    }
}
