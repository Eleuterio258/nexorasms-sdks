<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Internal\Json;

/**
 * One page of a list.
 *
 * @template T
 */
final class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $total,
    ) {
    }

    /**
     * True when there are more items after this page.
     */
    public function hasMore(): bool
    {
        return $this->page * $this->pageSize < $this->total;
    }

    /**
     * @internal
     *
     * @template U
     *
     * @param array<string, mixed>                $data
     * @param callable(array<string, mixed>): U   $map
     *
     * @return self<U>
     */
    public static function fromArray(array $data, callable $map): self
    {
        return new self(
            array_map($map, Json::objList($data, 'items')),
            Json::int($data, 'page'),
            Json::int($data, 'page_size'),
            Json::int($data, 'total'),
        );
    }
}
