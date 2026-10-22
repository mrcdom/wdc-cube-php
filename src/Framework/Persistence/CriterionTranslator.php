<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;
use Wdc\Framework\Domain\Criteria\Criterion;
use Wdc\Framework\Domain\Criteria\Operator;

/**
 * Tradução única de Criterion → SQL. Cada repositório só diz qual coluna corresponde a
 * cada campo (e, se preciso, como converter o valor).
 */
final class CriterionTranslator
{
    private function __construct()
    {
    }

    /**
     * @param Criterion<*, *>             $criterion
     * @param (Closure(mixed): mixed)|null $converter  ex.: DateTime → string do banco
     */
    public static function translate(string $column, Criterion $criterion, ?Closure $converter = null): ?Sql
    {
        if (!$criterion->isSet()) {
            return null;
        }

        $conditions = [];
        foreach ($criterion->predicates() as $predicate) {
            $op = $predicate->operator;
            if ($op === Operator::IS_NULL) {
                $conditions[] = new Sql("{$column} IS NULL");
                continue;
            }
            if ($op === Operator::IS_NOT_NULL) {
                $conditions[] = new Sql("{$column} IS NOT NULL");
                continue;
            }

            $values = $converter === null ? $predicate->values : array_map($converter, $predicate->values);
            if (!$op->accepts(count($values))) {
                continue;   // não emite SQL inválido
            }

            $conditions[] = match ($op) {
                Operator::EQ => new Sql("{$column} = ?", $values),
                Operator::NE => new Sql("{$column} <> ?", $values),
                Operator::GT => new Sql("{$column} > ?", $values),
                Operator::GE => new Sql("{$column} >= ?", $values),
                Operator::LT => new Sql("{$column} < ?", $values),
                Operator::LE => new Sql("{$column} <= ?", $values),
                Operator::LIKE => new Sql("{$column} LIKE ?", $values),
                Operator::ILIKE => new Sql("lower({$column}) LIKE lower(?)", $values),
                Operator::BETWEEN => new Sql("{$column} BETWEEN ? AND ?", $values),
                Operator::IN => new Sql(
                    "{$column} IN (" . implode(', ', array_fill(0, count($values), '?')) . ')',
                    $values,
                ),
            };
        }

        return $criterion->disjunctive() ? Sql::or($conditions) : Sql::and($conditions);
    }
}
