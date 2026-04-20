<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

use DateTimeImmutable;
use LogDB\Models\LogLevel;

/**
 * Filter + paging parameters for `LogDBReader::getLogs()`.
 *
 * Matches the .NET `SdkLogQueryRequest` DTO. All filter fields are optional;
 * unset (null) means "no filter". Mixes the well-known column filters
 * (application, level, correlationId, ...) with paging + sort.
 */
final class LogQueryParams
{
    /**
     * @param string|LogLevel|null $level  Either a `LogLevel` enum or the canonical string ("Info"/"Warning"/"Error"/...).
     */
    public function __construct(
        public ?string $application = null,
        public ?string $environment = null,
        public string|LogLevel|null $level = null,
        public ?string $collection = null,
        public ?string $correlationId = null,
        public ?string $source = null,
        public ?string $userEmail = null,
        public ?int $userId = null,
        public ?string $httpMethod = null,
        public ?string $requestPath = null,
        public ?string $ipAddress = null,
        public ?int $statusCode = null,
        public ?string $searchString = null,
        public ?bool $isException = null,
        public ?DateTimeImmutable $fromDate = null,
        public ?DateTimeImmutable $toDate = null,
        public int $skip = 0,
        public int $take = 50,
        public string $sortField = 'Timestamp',
        public bool $sortAscending = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'application' => $this->application,
            'environment' => $this->environment,
            'level' => $this->level instanceof LogLevel ? $this->level->toString() : $this->level,
            'collection' => $this->collection,
            'correlationId' => $this->correlationId,
            'source' => $this->source,
            'userEmail' => $this->userEmail,
            'userId' => $this->userId,
            'httpMethod' => $this->httpMethod,
            'requestPath' => $this->requestPath,
            'ipAddress' => $this->ipAddress,
            'statusCode' => $this->statusCode,
            'searchString' => $this->searchString,
            'isException' => $this->isException,
            'fromDate' => $this->fromDate?->format('Y-m-d\TH:i:s.u\Z'),
            'toDate' => $this->toDate?->format('Y-m-d\TH:i:s.u\Z'),
            'skip' => $this->skip,
            'take' => $this->take,
            'sortField' => $this->sortField,
            'sortAscending' => $this->sortAscending,
        ];
    }
}
