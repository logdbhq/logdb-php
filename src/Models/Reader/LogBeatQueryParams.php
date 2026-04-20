<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

use DateTimeImmutable;

final class LogBeatQueryParams
{
    /**
     * @param array<string, string>|null $tagFilters
     */
    public function __construct(
        public ?string $measurement = null,
        public ?string $collection = null,
        public ?DateTimeImmutable $fromDate = null,
        public ?DateTimeImmutable $toDate = null,
        public ?array $tagFilters = null,
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
            'measurement' => $this->measurement,
            'collection' => $this->collection,
            'fromDate' => $this->fromDate?->format('Y-m-d\TH:i:s.u\Z'),
            'toDate' => $this->toDate?->format('Y-m-d\TH:i:s.u\Z'),
            'tagFilters' => $this->tagFilters,
            'skip' => $this->skip,
            'take' => $this->take,
            'sortField' => $this->sortField,
            'sortAscending' => $this->sortAscending,
        ];
    }
}
