<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Critério de campo textual. Os curingas (%) fazem parte do valor, como em SQL.
 *
 * @template-covariant C of object
 * @extends ComparableCriterion<C, string>
 */
class TextCriterion extends ComparableCriterion
{
    /**
     * LIKE, sensível a maiúsculas. Texto nulo ou vazio não acrescenta pedido.
     *
     * @return C
     */
    public function like(?string $pattern): object
    {
        return self::isEmpty($pattern) ? $this->owner() : $this->add(Operator::LIKE, $pattern);
    }

    /**
     * ILIKE, insensível a maiúsculas.
     *
     * @return C
     */
    public function ilike(?string $pattern): object
    {
        return self::isEmpty($pattern) ? $this->owner() : $this->add(Operator::ILIKE, $pattern);
    }

    /**
     * ILIKE '%valor%'.
     *
     * @return C
     */
    public function containing(?string $fragment): object
    {
        return self::isEmpty($fragment) ? $this->owner() : $this->ilike('%' . $fragment . '%');
    }

    /**
     * ILIKE 'valor%' — ancorado no início.
     *
     * @return C
     */
    public function startingWith(?string $prefix): object
    {
        return self::isEmpty($prefix) ? $this->owner() : $this->ilike($prefix . '%');
    }

    /** @phpstan-assert-if-false non-empty-string $s  (ensina ao PHPStan: se devolveu false, $s é string) */
    private static function isEmpty(?string $s): bool
    {
        return $s === null || $s === '';
    }
}
