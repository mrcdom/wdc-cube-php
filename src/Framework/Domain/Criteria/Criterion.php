<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Criterion on a field: the comparison requests made and how they combine.
 *
 * Rules:
 * - successive requests accumulate and apply together (AND); or() makes the field disjunctive (OR within the field);
 * - a null value adds no request (empty screen filter = "don't filter");
 * - the fluent methods return the owner (the XxxCriteria) for chaining.
 *
 * @template-covariant C of object  type of the owning XxxCriteria (covariant: only appears in return types)
 * @template T            type of the compared value
 */
class Criterion
{
    /** @var list<Predicate<T>> */
    private array $predicates = [];

    private bool $disjunctive = false;

    /**
     * @param C $owner
     */
    public function __construct(
        private readonly object $owner,
        private readonly string $name,
    ) {
    }

    /** Logical field name. */
    public function name(): string
    {
        return $this->name;
    }

    /** @return list<Predicate<T>> */
    public function predicates(): array
    {
        return $this->predicates;
    }

    public function disjunctive(): bool
    {
        return $this->disjunctive;
    }

    /** true when there is at least one request, i.e. when the field must become a condition. */
    public function isSet(): bool
    {
        return $this->predicates !== [];
    }

    /** Marks the field as disjunctive: its requests are combined with OR. */
    public function or(): static
    {
        $this->disjunctive = true;

        return $this;
    }

    /** Requires all requests again (AND). */
    public function and(): static
    {
        $this->disjunctive = false;

        return $this;
    }

    /**
     * Clears this field's requests. Does not undo or().
     *
     * @return C
     */
    public function clear(): object
    {
        $this->predicates = [];

        return $this->owner;
    }

    /**
     * @param T|null $value
     * @return C
     */
    public function eq(mixed $value): object
    {
        return $value === null ? $this->owner : $this->add(Operator::EQ, $value);
    }

    /**
     * @param T|null $value
     * @return C
     */
    public function ne(mixed $value): object
    {
        return $value === null ? $this->owner : $this->add(Operator::NE, $value);
    }

    /**
     * Belongs to the set. A null or empty set adds no request.
     *
     * @param iterable<T>|null $values
     * @return C
     */
    public function in(?iterable $values): object
    {
        if ($values === null) {
            return $this->owner;
        }
        $list = [];
        foreach ($values as $v) {
            $list[] = $v;
        }
        if ($list === []) {
            return $this->owner;
        }

        return $this->add(Operator::IN, ...$list);
    }

    /** @return C */
    public function isNull(): object
    {
        return $this->add(Operator::IS_NULL);
    }

    /** @return C */
    public function isNotNull(): object
    {
        return $this->add(Operator::IS_NOT_NULL);
    }

    /**
     * Restores a request exactly as it came over the wire, bypassing the fluent methods' rules.
     *
     * Values are not checked against T: whoever reads the wire format (CriterionCodec) has already
     * converted them using the field's declared type.
     *
     * @param list<mixed> $values
     */
    public function restore(Operator $operator, array $values): void
    {
        $this->predicates[] = new Predicate($operator, $values);
    }

    /**
     * @param T ...$values
     * @return C
     */
    protected function add(Operator $operator, mixed ...$values): object
    {
        $this->predicates[] = new Predicate($operator, array_values($values));

        return $this->owner;
    }

    /** @return C */
    protected function owner(): object
    {
        return $this->owner;
    }
}
