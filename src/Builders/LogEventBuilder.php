<?php

declare(strict_types=1);

namespace LogDB\Builders;

use DateTimeImmutable;
use LogDB\LogDBClientLike;
use LogDB\Models\Log;
use LogDB\Models\LogLevel;
use LogDB\Models\LogResponseStatus;
use Throwable;

/**
 * Fluent, immutable builder for `Log` entries. Each setter returns a new builder
 * wrapping a freshly cloned log.
 *
 * @example
 *   LogEventBuilder::create($client)
 *       ->setMessage('user logged in')
 *       ->setLogLevel(LogLevel::Info)
 *       ->setUserEmail('alice@example.com')
 *       ->addAttribute('tenant', 'acme')
 *       ->addLabel('auth')
 *       ->log();
 */
final class LogEventBuilder
{
    private function __construct(
        private readonly Log $entry,
        private readonly LogDBClientLike $client,
    ) {
    }

    public static function create(LogDBClientLike $client): self
    {
        return new self(new Log(message: ''), $client);
    }

    /** Direct access to the underlying entry (for tests / debugging). */
    public function build(): Log
    {
        return $this->entry;
    }

    public function setMessage(string $message): self
    {
        return $this->with(['message' => $message]);
    }

    public function setLogLevel(LogLevel $level): self
    {
        return $this->with(['level' => $level]);
    }

    public function setApplication(string $application): self
    {
        return $this->with(['application' => $application]);
    }

    public function setEnvironment(string $environment): self
    {
        return $this->with(['environment' => $environment]);
    }

    public function setCollection(string $collection): self
    {
        return $this->with(['collection' => $collection]);
    }

    public function setSource(string $source): self
    {
        return $this->with(['source' => $source]);
    }

    public function setUserId(int $userId): self
    {
        return $this->with(['userId' => $userId]);
    }

    public function setUserEmail(string $userEmail): self
    {
        return $this->with(['userEmail' => $userEmail]);
    }

    public function setCorrelationId(string $correlationId): self
    {
        return $this->with(['correlationId' => $correlationId]);
    }

    public function setRequestPath(string $requestPath): self
    {
        return $this->with(['requestPath' => $requestPath]);
    }

    public function setHttpMethod(string $httpMethod): self
    {
        return $this->with(['httpMethod' => $httpMethod]);
    }

    public function setStatusCode(int $statusCode): self
    {
        return $this->with(['statusCode' => $statusCode]);
    }

    public function setIpAddress(string $ipAddress): self
    {
        return $this->with(['ipAddress' => $ipAddress]);
    }

    public function setDescription(string $description): self
    {
        return $this->with(['description' => $description]);
    }

    public function setAdditionalData(string $additionalData): self
    {
        return $this->with(['additionalData' => $additionalData]);
    }

    public function setStackTrace(string $stackTrace): self
    {
        return $this->with(['stackTrace' => $stackTrace]);
    }

    public function setGuid(string $guid): self
    {
        return $this->with(['guid' => $guid]);
    }

    public function setTimestamp(DateTimeImmutable $timestamp): self
    {
        return $this->with(['timestamp' => $timestamp]);
    }

    public function setException(Throwable $error): self
    {
        return $this->with([
            'exception' => $error::class . ': ' . $error->getMessage(),
            'stackTrace' => $error->getTraceAsString(),
            'level' => $this->entry->level ?? LogLevel::Error,
        ]);
    }

    public function addLabel(string $label): self
    {
        $next = $this->entry->label ?? [];
        $next[] = $label;
        return $this->with(['label' => $next]);
    }

    /** @param string|int|float|bool|DateTimeImmutable $value */
    public function addAttribute(string $key, string|int|float|bool|DateTimeImmutable $value): self
    {
        if (is_string($value)) {
            $next = $this->entry->attributesS ?? [];
            $next[$key] = $value;
            return $this->with(['attributesS' => $next]);
        }
        if (is_int($value) || is_float($value)) {
            $next = $this->entry->attributesN ?? [];
            $next[$key] = $value;
            return $this->with(['attributesN' => $next]);
        }
        if (is_bool($value)) {
            $next = $this->entry->attributesB ?? [];
            $next[$key] = $value;
            return $this->with(['attributesB' => $next]);
        }
        $next = $this->entry->attributesD ?? [];
        $next[$key] = $value;
        return $this->with(['attributesD' => $next]);
    }

    public function log(): LogResponseStatus
    {
        return $this->client->logEntry($this->entry);
    }

    /** @param array<string, mixed> $patch */
    private function with(array $patch): self
    {
        return new self($this->entry->with($patch), $this->client);
    }
}
