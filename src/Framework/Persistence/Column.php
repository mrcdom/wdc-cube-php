<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;

/**
 * Mapping between a bean field and a column.
 *
 * @template E of object
 */
final readonly class Column
{
    /**
     * @param Closure(E): mixed        $get
     * @param Closure(E, mixed): mixed $set
     */
    public function __construct(
        public string $field,
        public string $column,
        public ColumnType $type,
        public Closure $get,
        public Closure $set,
    ) {
    }

    /** @param E $bean */
    public function read(object $bean): mixed
    {
        return ($this->get)($bean);   // parentheses: call the closure stored in the property
    }

    /** @param E $bean */
    public function write(object $bean, mixed $dbValue): void
    {
        ($this->set)($bean, $this->type->toPhp($dbValue));
    }
}
