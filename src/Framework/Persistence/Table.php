<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;

/**
 * Describes a table: name, bean factory and columns. The first column is the key.
 * Used for writes (INSERT/UPDATE) and, through the JsonQuery, for reads.
 *
 * @template E of object
 */
final readonly class Table
{
    /**
     * @param Closure(): E            $factory
     * @param non-empty-list<Column<E>> $columns   the first one is the ID
     */
    public function __construct(
        public string $name,
        private Closure $factory,
        public array $columns,
    ) {
    }

    /** @return E */
    public function newBean(): object
    {
        return ($this->factory)();
    }

    /** @return Column<E> */
    public function id(): Column
    {
        return $this->columns[0];
    }

    /**
     * Columns requested by the projection (non-null field); the ID is always included.
     *
     * @param E $projection
     * @return non-empty-list<Column<E>>
     */
    public function projected(object $projection): array
    {
        $result = [$this->id()];
        foreach (array_slice($this->columns, 1) as $column) {
            if ($column->read($projection) !== null) {
                $result[] = $column;
            }
        }

        return $result;
    }

    /**
     * true when the projection requests no column besides the ID (relations are checked by the JsonQuery).
     *
     * @param E $projection
     */
    public function isIdOnly(object $projection): bool
    {
        foreach (array_slice($this->columns, 1) as $column) {
            if ($column->read($projection) !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Column<E>>      $columns
     * @return E
     */
    public function hydrate(array $row, array $columns): object
    {
        $bean = $this->newBean();
        foreach ($columns as $column) {
            $column->write($bean, $row[$column->column] ?? null);
        }

        return $bean;
    }
}
