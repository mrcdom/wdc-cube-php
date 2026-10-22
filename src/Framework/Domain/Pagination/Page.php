<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Pagination;

/**
 * Uma página de resultados (page é 0-based).
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $totalPages,
        public int $totalItems,
    ) {
    }

    /**
     * @template U
     * @param list<U> $items
     * @return self<U>
     */
    public static function of(array $items, int $page, int $pageSize, int $totalItems): self
    {
        $totalPages = $pageSize > 0 ? (int) ceil($totalItems / $pageSize) : 0;

        return new self($items, $page, $totalPages, $totalItems);
    }
}
