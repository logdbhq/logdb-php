<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

use DateTimeImmutable;

final class LogCacheQueryParams
{
    public function __construct(
        public ?string $keyPattern = null,
        public ?string $collection = null,
        public ?DateTimeImmutable $fromDate = null,
        public ?DateTimeImmutable $toDate = null,
        public int $skip = 0,
        public int $take = 50,
        public string $sortField = 'CreatedAt',
        public bool $sortAscending = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'keyPattern' => $this->keyPattern,
            'collection' => $this->collection,
            'fromDate' => $this->fromDate?->format('Y-m-d\TH:i:s.u\Z'),
            'toDate' => $this->toDate?->format('Y-m-d\TH:i:s.u\Z'),
            'skip' => $this->skip,
            'take' => $this->take,
            'sortField' => $this->sortField,
            'sortAscending' => $this->sortAscending,
        ];
    }
}
