<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

use stdClass;
use UnitEnum;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * Como um campo de critério trafega — escrito uma vez aqui, e não repetido em cada codec de
 * entidade (o que muda de campo para campo é só o tipo do valor):
 *
 *     "price": { "or": true, "p": [ { "o": "GE", "v": [10.0] }, { "o": "IS_NULL" } ] }
 *
 * O valor solto (`"price": 10.0`) não bastaria: um campo carrega vários pedidos, cada um com
 * seu operador e aridade, e a disjunção é do campo. Reduzir a forma descartaria tudo menos a
 * igualdade — em silêncio, devolvendo linhas a mais. Mas o valor solto é **aceito** na
 * leitura, como igualdade.
 */
final class CriterionCodec
{
    private function __construct()
    {
    }

    /**
     * Escreve o campo em $out — se houver o que escrever: campo sem pedido não gera chave.
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
     * Lê o campo e repõe os pedidos no criterion — por `restore()`, não pelos métodos
     * fluentes: `eq(null)` não acrescenta pedido e apagaria em silêncio o que veio do outro lado.
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
     * Lê o nome da ordenação, **recusando** o que não conhece — ao contrário do operador
     * desconhecido, que é descartado. Sem um pedido o filtro fica mais frouxo e o campo continua
     * listado; sem a ordenação, a lista volta numa ordem qualquer e ninguém percebe.
     *
     * @template E of UnitEnum
     * @param list<E> $accepted
     * @return E|null
     * @throws InvalidRequestException nomeando o valor recebido e os aceitos (400)
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

        throw new InvalidRequestException("ordenação desconhecida: '{$name}' — aceitas: {$names}");
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
     * Operador desconhecido (o outro lado é mais novo) é descartado, não traduzido por
     * aproximação: o filtro "mais parecido" seria outro filtro, sem nada assinalar.
     */
    private static function parseOperator(?string $name): ?Operator
    {
        return $name === null ? null : Operator::tryFrom($name);
    }
}
