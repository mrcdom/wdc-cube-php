<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

use Closure;
use JsonException;
use LogicException;
use Wdc\Framework\Domain\Projection\ProjectionList;
use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Dialect\SqlDialect;
use Wdc\Framework\Persistence\Sql;
use Wdc\Framework\Persistence\Table;

/**
 * Consulta por projeção em JSON.
 *
 * Um único SELECT devolve, por linha, um objeto JSON com exatamente os campos projetados.
 * As relações projetadas entram como subconsultas correlacionadas DENTRO do JSON:
 *
 *     SELECT {
 *         "id": p1.ID,
 *         "user": (SELECT {"id": u2.ID, "name": u2.NAME} FROM EN_USER u2 WHERE u2.ID = p1.USERID),
 *         "items": (SELECT [ {...} ORDER BY pi3.ID ] FROM EN_PURCHASEITEM pi3 WHERE pi3.PURCHASEID = p1.ID)
 *     } AS json
 *     FROM EN_PURCHASE p1 WHERE ...
 *
 * (em pseudo-SQL: `{...}` e `[...]` são montados com as funções JSON do banco — ver JsonDialect).
 *
 * O grafo inteiro vem numa ida ao banco, sem N+1, e só com o que a tela pediu.
 *
 * Atalho de chave: se a projeção de uma relação N:1 pede só o id, ele já está na coluna de
 * FK desta linha — sai `{"id": p1.USERID}` sem subconsulta.
 *
 * Relações são registradas depois da construção (`addToOne`/`addToMany`, com o alvo numa
 * closure) porque as consultas se referenciam em ciclo (compra → item → compra).
 *
 * @template E of object  entidade
 * @template C of object  critério da entidade (filtro e ordem das subconsultas)
 */
final class JsonQuery
{
    /** @var array<string, array{get: Closure, set: Closure, target: Closure, fk: string}> */
    private array $toOne = [];

    /** @var array<string, array{get: Closure, set: Closure, target: Closure, fk: string}> */
    private array $toMany = [];

    private readonly JsonDialect $dialect;

    /** @var Closure(C, string, QueryContext): ?Sql */
    private Closure $conditions;

    /** @var Closure(C|null, string): string */
    private Closure $ordering;

    /**
     * @param Table<E>        $table
     * @param class-string<C> $criteriaClass
     */
    public function __construct(
        public readonly Table $table,
        private readonly string $criteriaClass,
        private readonly string $aliasPrefix,
        private readonly SqlDialect $sqlDialect,
    ) {
        $this->dialect = $sqlDialect->json();
        $this->conditions = static fn (): ?Sql => null;
        $this->ordering = static fn (): string => '';
    }

    /**
     * Como o critério desta entidade vira WHERE, contra um alias qualquer.
     *
     * @param Closure(C, string, QueryContext): ?Sql $conditions
     * @return $this
     */
    public function withConditions(Closure $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Como o OrderBy do critério vira lista de ordenação, contra um alias qualquer.
     *
     * @param Closure(C|null, string): string $ordering
     * @return $this
     */
    public function withOrdering(Closure $ordering): self
    {
        $this->ordering = $ordering;

        return $this;
    }

    /**
     * Relação N:1: esta tabela guarda a FK do alvo (ex.: EN_PURCHASE.USERID → EN_USER.ID).
     *
     * @template P of object
     * @param Closure(E): (P|null)         $get    getter da relação no bean (a máscara, na projeção)
     * @param Closure(E, P|null): mixed    $set
     * @param Closure(): JsonQuery<P, *>   $target consulta do alvo (closure: resolve ciclos)
     * @return $this
     */
    public function addToOne(string $field, Closure $get, Closure $set, Closure $target, string $fkColumn): self
    {
        $this->toOne[$field] = ['get' => $get, 'set' => $set, 'target' => $target, 'fk' => $fkColumn];

        return $this;
    }

    /**
     * Relação 1:N: o alvo guarda a FK desta tabela (ex.: EN_PURCHASEITEM.PURCHASEID → EN_PURCHASE.ID).
     *
     * @template P of object
     * @param Closure(E): (array<P>|ProjectionList<P>|null) $get
     * @param Closure(E, list<P>): mixed                     $set
     * @param Closure(): JsonQuery<P, *>                     $target
     * @return $this
     */
    public function addToMany(string $field, Closure $get, Closure $set, Closure $target, string $childFkColumn): self
    {
        $this->toMany[$field] = ['get' => $get, 'set' => $set, 'target' => $target, 'fk' => $childFkColumn];

        return $this;
    }

    /**
     * Aceita `object` porque o critério de uma ProjectionList chega sem tipo; confere aqui.
     */
    public function conditions(?object $criteria, string $alias, QueryContext $ctx): ?Sql
    {
        return $criteria === null ? null : ($this->conditions)($this->checked($criteria), $alias, $ctx);
    }

    public function ordering(?object $criteria, string $alias): string
    {
        return ($this->ordering)($criteria === null ? null : $this->checked($criteria), $alias);
    }

    public function newAlias(QueryContext $ctx): string
    {
        return $ctx->alias($this->aliasPrefix);
    }

    /**
     * SELECT completo: uma coluna "json" por linha.
     *
     * @param E $projection
     */
    public function select(object $projection, ?object $criteria, int $offset, int $limit, QueryContext $ctx): Sql
    {
        $alias = $this->newAlias($ctx);
        $json = $this->projection($projection, $alias, $ctx);
        [$where, $whereParams] = Sql::where($this->conditions($criteria, $alias, $ctx));

        $sql = "SELECT {$json->sql} AS json FROM {$this->table->name} {$alias}{$where}";
        // sem ordenação pedida, a do id: sem ORDER BY o banco devolve as linhas em qualquer ordem
        $order = $this->ordering($criteria, $alias);
        $sql .= ' ORDER BY ' . ($order !== '' ? $order : "{$alias}.{$this->table->id()->column} ASC");
        $sql .= $this->sqlDialect->limit($limit, $offset);

        return new Sql($sql, [...$json->params, ...$whereParams]);
    }

    /**
     * A expressão json_object(...) desta entidade, para a linha do alias dado.
     *
     * @param E $projection
     */
    public function projection(object $projection, string $alias, QueryContext $ctx): Sql
    {
        $fields = [];
        foreach ($this->table->projected($projection) as $column) {
            $fields[] = new JsonField($column->field, new Sql("{$alias}.{$column->column}"), $column->type);
        }
        foreach ($this->toOne as $name => $relation) {
            $mask = ($relation['get'])($projection);
            if ($mask !== null) {
                $fields[] = new JsonField($name, $this->toOneExpr(self::targetOf($relation), $mask, $alias, $relation['fk'], $ctx), null);
            }
        }
        foreach ($this->toMany as $name => $relation) {
            $collection = ($relation['get'])($projection);
            if ($collection !== null) {
                $expr = $this->toManyExpr(self::targetOf($relation), $collection, $alias, $relation['fk'], $ctx);
                if ($expr !== null) {
                    $fields[] = new JsonField($name, $expr, null);
                }
            }
        }

        return $this->dialect->jsonObject($fields);
    }

    /**
     * @return E
     */
    public function parse(string $json): object
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);   // true = arrays, não stdClass
        } catch (JsonException $e) {
            throw new LogicException("JSON inválido vindo do banco: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new LogicException('JSON do banco não é um objeto');
        }

        return $this->hydrate($data);
    }

    /**
     * Monta o bean a partir do array decodificado. Chaves ausentes ficam null (não projetadas).
     *
     * @param array<mixed> $data
     * @return E
     */
    public function hydrate(array $data): object
    {
        $bean = $this->table->newBean();
        foreach ($this->table->columns as $column) {
            if (!array_key_exists($column->field, $data)) {
                continue;
            }
            $value = $data[$column->field];
            if ($column->type === ColumnType::Blob && is_string($value)) {
                ($column->set)($bean, $this->dialect->decodeBinary($value));
            } else {
                $column->write($bean, $value);
            }
        }
        foreach ($this->toOne as $name => $relation) {
            if (array_key_exists($name, $data)) {
                $value = $data[$name];
                ($relation['set'])($bean, is_array($value) ? self::targetOf($relation)->hydrate($value) : null);
            }
        }
        foreach ($this->toMany as $name => $relation) {
            if (array_key_exists($name, $data)) {
                $target = self::targetOf($relation);
                $items = [];
                foreach (is_array($data[$name]) ? $data[$name] : [] as $item) {
                    if (is_array($item)) {
                        $items[] = $target->hydrate($item);
                    }
                }
                ($relation['set'])($bean, $items);
            }
        }

        return $bean;
    }

    /**
     * true se a máscara não pede nada além do id (nem campos, nem relações).
     *
     * @param E $mask
     */
    public function isIdOnly(object $mask): bool
    {
        if (!$this->table->isIdOnly($mask)) {
            return false;
        }
        foreach ([...$this->toOne, ...$this->toMany] as $relation) {
            if (($relation['get'])($mask) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param JsonQuery<object, object> $target
     */
    private function toOneExpr(JsonQuery $target, object $mask, string $alias, string $fk, QueryContext $ctx): Sql
    {
        $fkRef = "{$alias}.{$fk}";
        if ($target->isIdOnly($mask)) {
            $idField = $target->table->id()->field;
            $object = $this->dialect->jsonObject([new JsonField($idField, new Sql($fkRef), ColumnType::Int)]);

            return new Sql("CASE WHEN {$fkRef} IS NULL THEN NULL ELSE {$object->sql} END", $object->params);
        }

        $child = $target->newAlias($ctx);
        $json = $target->projection($mask, $child, $ctx);
        $pk = $target->table->id()->column;

        return new Sql(
            "(SELECT {$json->sql} FROM {$target->table->name} {$child} WHERE {$child}.{$pk} = {$fkRef})",
            $json->params,
        );
    }

    /**
     * Coleção: subconsulta que agrega os filhos num array JSON, com a ordem DENTRO do agregado.
     * O recorte (limit/offset por pai) vira `ID IN (subconsulta correlacionada ordenada e cortada)`
     * — não cabe no agregado nem numa tabela derivada, que não enxergaria a linha do pai.
     *
     * @param JsonQuery<object, object>                 $target
     * @param array<object>|ProjectionList<object>      $collection
     */
    private function toManyExpr(JsonQuery $target, array|ProjectionList $collection, string $alias, string $fk, QueryContext $ctx): ?Sql
    {
        if ($collection instanceof ProjectionList) {
            $mask = $collection->shape;
            $criteria = $collection->criteria;
            $limit = $collection->limit() ?? 0;
            $offset = $collection->offset() ?? 0;
        } else {
            $mask = $collection[array_key_first($collection) ?? 0] ?? null;
            $criteria = null;
            $limit = $offset = 0;
        }
        if ($mask === null) {
            return null;
        }

        $pk = $this->table->id()->column;
        $childPk = $target->table->id()->column;
        $child = $target->newAlias($ctx);
        $element = $target->projection($mask, $child, $ctx);
        $order = $target->ordering($criteria, $child);
        $agg = $this->dialect->jsonArrayAgg($element, $order !== '' ? $order : "{$child}.{$childPk} ASC");

        $conditions = [
            new Sql("{$child}.{$fk} = {$alias}.{$pk}"),
            $target->conditions($criteria, $child, $ctx),
        ];
        if ($limit > 0 || $offset > 0) {
            $slice = $target->newAlias($ctx);
            [$sliceWhere, $sliceParams] = Sql::where(Sql::and([
                new Sql("{$slice}.{$fk} = {$alias}.{$pk}"),
                $target->conditions($criteria, $slice, $ctx),
            ]));
            $sliceOrder = $target->ordering($criteria, $slice);
            $conditions[] = new Sql(
                "{$child}.{$childPk} IN (SELECT {$slice}.{$childPk} FROM {$target->table->name} {$slice}{$sliceWhere}"
                . ' ORDER BY ' . ($sliceOrder !== '' ? $sliceOrder : "{$slice}.{$childPk} ASC")
                . $target->sqlDialect->limit($limit, $offset) . ')',
                $sliceParams,
            );
        }
        [$where, $whereParams] = Sql::where(Sql::and($conditions));

        return new Sql(
            "(SELECT {$agg->sql} FROM {$target->table->name} {$child}{$where})",
            [...$agg->params, ...$whereParams],
        );
    }

    /** @return C */
    private function checked(object $criteria): object
    {
        if (!$criteria instanceof $this->criteriaClass) {
            throw new LogicException(sprintf(
                'Critério %s não serve para %s (esperado %s)',
                $criteria::class,
                $this->table->name,
                $this->criteriaClass,
            ));
        }

        return $criteria;
    }

    /**
     * As relações guardam o alvo numa closure sem tipo; os tipos são conferidos em addToOne/addToMany.
     *
     * @param array{target: Closure} $relation
     * @return JsonQuery<object, object>
     */
    private static function targetOf(array $relation): self
    {
        $target = ($relation['target'])();
        if (!$target instanceof self) {
            throw new LogicException('Alvo de relação não é uma JsonQuery');
        }

        return $target;
    }
}
