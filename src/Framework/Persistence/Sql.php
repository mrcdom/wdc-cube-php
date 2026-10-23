<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;

/**
 * A piece of SQL with its positional parameters (?).
 * `null` in place of a Sql means "no condition".
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
     * Joins with AND, dropping nulls. No conditions ⇒ null (= TRUE, never FALSE).
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
     * Column prefixer for an alias: `$c = Sql::alias('pi2'); $c(self::PRICE)` → `pi2.PRICE`.
     * Works inside string interpolation: `"{$c(self::PRICE)} DESC, {$c(self::ID)} ASC"` - PHP does
     * not interpolate constants, but it does interpolate a call to a closure held in a variable.
     *
     * @return Closure(string): string
     */
    public static function alias(string $alias): Closure
    {
        return static fn (string $column): string => "{$alias}.{$column}";
    }

    /**
     * " WHERE ..." ready to concatenate, or "" when there is no condition.
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
