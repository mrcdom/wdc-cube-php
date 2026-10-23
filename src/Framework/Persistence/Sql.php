<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;

/**
 * Pedaço de SQL com seus parâmetros posicionais (?).
 * `null` no lugar de uma Sql significa "sem condição".
 */
final readonly class Sql
{
    /**
     * @param list<mixed> $params
     */
    public function __construct(
        public string $sql,
        public array $params = [],
    ) {
    }

    /**
     * Junta com AND, descartando nulls. Nenhuma condição ⇒ null (= TRUE, nunca FALSE).
     *
     * @param iterable<Sql|null> $conditions
     */
    public static function and(iterable $conditions): ?self
    {
        return self::join(' AND ', $conditions);
    }

    /** @param iterable<Sql|null> $conditions */
    public static function or(iterable $conditions): ?self
    {
        return self::join(' OR ', $conditions);
    }

    /**
     * Prefixador de colunas de um alias: `$c = Sql::alias('pi2'); $c(self::PRICE)` → `pi2.PRICE`.
     * Interpolável numa string: `"{$c(self::PRICE)} DESC, {$c(self::ID)} ASC"` - o PHP não
     * interpola constantes, mas interpola a chamada de uma closure guardada numa variável.
     *
     * @return Closure(string): string
     */
    public static function alias(string $alias): Closure
    {
        return static fn (string $column): string => "{$alias}.{$column}";
    }

    /**
     * " WHERE ..." pronto para concatenar, ou "" sem condição.
     *
     * @return array{string, list<mixed>}
     */
    public static function where(?self $condition): array
    {
        return $condition === null ? ['', []] : [' WHERE ' . $condition->sql, $condition->params];
    }

    /** @param iterable<Sql|null> $conditions */
    private static function join(string $glue, iterable $conditions): ?self
    {
        $parts = [];
        foreach ($conditions as $c) {
            if ($c !== null) {
                $parts[] = $c;
            }
        }
        if ($parts === []) {
            return null;
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        $sql = implode($glue, array_map(static fn (self $c): string => '(' . $c->sql . ')', $parts));

        return new self($sql, array_merge(...array_map(static fn (self $c): array => $c->params, $parts)));
    }
}
