<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Um pedido de comparação: o operador e os valores que o acompanham.
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

    /** @return T|null primeiro valor — atalho para operadores de um valor só */
    public function value(): mixed
    {
        return $this->values[0] ?? null;
    }
}
