<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Operador de comparação de um Criterion, com a mesma semântica do SQL.
 * Neutro: não conhece SQL — quem traduz é o CriterionTranslator da persistência.
 */
enum Operator: string
{
    case EQ = 'EQ';
    case NE = 'NE';
    case GT = 'GT';
    case GE = 'GE';
    case LT = 'LT';
    case LE = 'LE';
    case LIKE = 'LIKE';
    case ILIKE = 'ILIKE';
    case BETWEEN = 'BETWEEN';
    case IN = 'IN';
    case IS_NULL = 'IS_NULL';
    case IS_NOT_NULL = 'IS_NOT_NULL';

    /** Quantidade de valores exigida; -1 quando variável (IN). */
    public function arity(): int
    {
        return match ($this) {
            self::BETWEEN => 2,
            self::IN => -1,
            self::IS_NULL, self::IS_NOT_NULL => 0,
            default => 1,
        };
    }

    public function accepts(int $count): bool
    {
        $arity = $this->arity();

        return $arity < 0 ? $count > 0 : $count === $arity;
    }
}
