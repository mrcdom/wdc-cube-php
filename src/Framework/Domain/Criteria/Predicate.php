<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * A comparison request: the operator and the values that go with it.
 *
 * @template T
 */
final readonly class Predicate
{
    /**
     * @param list<T> $values
     */
    public function __construct(
        public Operator $operator,
        public array $values,
    ) {
    }

    /** @return T|null first value — shortcut for single-value operators */
    public function value(): mixed
    {
        return $this->values[0] ?? null;
    }
}
