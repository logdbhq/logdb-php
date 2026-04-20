<?php

declare(strict_types=1);

namespace LogDB\Models\Reader;

/**
 * Pagination envelope returned by every reader query method.
 *
 * Matches the .NET `SdkPagedResult<T>` shape: an array of items plus
 * total / page / pageSize / hasMore. Use `hasMore` to know whether
 * another `getLogs(...)` call with `skip += take` is needed.
 *
 * @template T
 */
final class LogPage
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $totalCount,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly bool $hasMore,
    ) {
    }

    /**
     * Build from the JSON-decoded response body returned by the reader API.
     *
     * @param array<string, mixed>            $raw
     * @param callable(array<string, mixed>): T $itemMapper
     * @return self<T>
     */
    public static function fromJson(array $raw, callable $itemMapper): self
    {
        $items = [];
        foreach ((array) ($raw['items'] ?? []) as $rawItem) {
            if (is_array($rawItem)) {
                $items[] = $itemMapper($rawItem);
            }
        }
        return new self(
            items: $items,
            totalCount: (int) ($raw['totalCount'] ?? 0),
            page: (int) ($raw['page'] ?? 0),
            pageSize: (int) ($raw['pageSize'] ?? 0),
            hasMore: (bool) ($raw['hasMore'] ?? false),
        );
    }
}
