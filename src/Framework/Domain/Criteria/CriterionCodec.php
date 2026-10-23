<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

use stdClass;
use UnitEnum;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * How a criteria field travels over the wire — written once here rather than repeated in every
 * entity codec (the only thing that varies from field to field is the value type):
 *
 *     "price": { "or": true, "p": [ { "o": "GE", "v": [10.0] }, { "o": "IS_NULL" } ] }
 *
 * A bare value (`"price": 10.0`) would not be enough: a field carries several requests, each with
 * its own operator and arity, and the disjunction belongs to the field. Reducing the shape would
 * drop everything but equality — silently, returning extra rows. A bare value is, however,
 * **accepted** on read, as equality.
 */
final class CriterionCodec
{
    private function __construct()
    {
    }

    /**
     * Writes the field into $out — if there is anything to write: a field with no requests produces no key.
     *
     * @param array<string, mixed> $out
     * @param Criterion<*, *> $criterion
     * @param-out array<string, mixed> $out
     */
    public static function write(array &$out, string $name, Criterion $criterion, CriterionValue $type): void
    {
        if (!$criterion->isSet()) {
            return;
        }
        $field = new stdClass();
        if ($criterion->disjunctive()) {
            $field->or = true;
        }
        $predicates = [];
        foreach ($criterion->predicates() as $predicate) {
            $p = new stdClass();
            $p->o = $predicate->operator->name;
            if ($predicate->values !== []) {
                $p->v = array_map(static fn (mixed $v) => $v === null ? null : $type->write($v), $predicate->values);
            }
            $predicates[] = $p;
        }
        $field->p = $predicates;
        $out[$name] = $field;
    }

    /**
     * Reads the field and restores the requests into the criterion — via `restore()`, not the fluent
     * methods: `eq(null)` adds no request and would silently drop what came from the other side.
     *
     * @param Criterion<*, *> $criterion
     */
    public static function read(mixed $in, Criterion $criterion, CriterionValue $type): void
    {
        if (!$in instanceof stdClass) {
            $value = $type->read($in);
            if ($value !== null) {
                $criterion->restore(Operator::EQ, [$value]);
            }

            return;
        }
        foreach (Json::fields($in) as $name => $value) {
            match ($name) {
                'or' => InputCoerce::asBool($value) === true ? $criterion->or() : $criterion->and(),
                'p' => self::readPredicates($value, $criterion, $type),
                default => null,
            };
        }
    }

    /**
     * Reads the ordering name, **rejecting** unknown ones — unlike an unknown operator, which is
     * dropped. Without a request the filter just gets looser and the field is still listed; without
     * the ordering, the list comes back in arbitrary order and nobody notices.
     *
     * @template E of UnitEnum
     * @param list<E> $accepted
     * @return E|null
     * @throws InvalidRequestException naming the received value and the accepted ones (400)
     */
    public static function readOrderBy(mixed $in, array $accepted): ?UnitEnum
    {
        $name = InputCoerce::asString($in);
        if ($name === null) {
            return null;
        }
        foreach ($accepted as $candidate) {
            if ($candidate->name === $name) {
                return $candidate;
            }
        }
        $names = implode(', ', array_map(static fn (UnitEnum $c) => $c->name, $accepted));

        throw new InvalidRequestException("unknown ordering: '{$name}' — accepted: {$names}");
    }

    /** @param Criterion<*, *> $criterion */
    private static function readPredicates(mixed $in, Criterion $criterion, CriterionValue $type): void
    {
        foreach (Json::elements($in, 'p') as $item) {
            $operator = null;
            $values = [];
            foreach (Json::fields($item, 'p[]') as $name => $value) {
                if ($name === 'o') {
                    $operator = self::parseOperator(InputCoerce::asString($value));
                } elseif ($name === 'v') {
                    foreach (Json::elements($value, 'v') as $v) {
                        $values[] = $v === null ? null : $type->read($v);
                    }
                }
            }
            if ($operator !== null) {
                $criterion->restore($operator, $values);
            }
        }
    }

    /**
     * An unknown operator (the other side is newer) is dropped, not approximated: the "closest"
     * filter would be a different filter, with nothing to flag it.
     */
    private static function parseOperator(?string $name): ?Operator
    {
        return $name === null ? null : Operator::tryFrom($name);
    }
}
