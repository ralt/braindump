<?php

namespace App\Pagination;

/**
 * @template T
 */
final class Pager
{
    /**
     * @param T[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public function pageCount(): int
    {
        return (int) max(1, ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pageCount();
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }
}
