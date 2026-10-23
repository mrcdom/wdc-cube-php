<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Criterion on a text field. Wildcards (%) are part of the value, as in SQL.
 *
 * @template-covariant C of object
 * @extends ComparableCriterion<C, string>
 */
class TextCriterion extends ComparableCriterion
{
    /**
     * LIKE, case-sensitive. Null or empty text adds no request.
     *
     * @return C
     */
    public function like(?string $pattern): object
    {
        return self::isEmpty($pattern) ? $this->owner() : $this->add(Operator::LIKE, $pattern);
    }

    /**
     * ILIKE, case-insensitive.
     *
     * @return C
     */
    public function ilike(?string $pattern): object
    {
        return self::isEmpty($pattern) ? $this->owner() : $this->add(Operator::ILIKE, $pattern);
    }

    /**
     * ILIKE '%value%'.
     *
     * @return C
     */
    public function containing(?string $fragment): object
    {
        return self::isEmpty($fragment) ? $this->owner() : $this->ilike('%' . $fragment . '%');
    }

    /**
     * ILIKE 'value%' — anchored at the start.
     *
     * @return C
     */
    public function startingWith(?string $prefix): object
    {
        return self::isEmpty($prefix) ? $this->owner() : $this->ilike($prefix . '%');
    }

    /** @phpstan-assert-if-false non-empty-string $s  (tells PHPStan: if this returned false, $s is a string) */
    private static function isEmpty(?string $s): bool
    {
        return $s === null || $s === '';
    }
}
