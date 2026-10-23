<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;
use DateTimeInterface;
use LogicException;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Pagination\Page;
use Wdc\Framework\Domain\Repository\Repository;
use Wdc\Framework\Persistence\Json\JsonQuery;
use Wdc\Framework\Persistence\Json\QueryContext;

/**
 * Base dos repositórios SQL. A leitura vai toda pela JsonQuery (projeção em JSON, uma ida ao
 * banco com o grafo inteiro); a escrita é campo a campo pela Table. Cada subclasse descreve a
 * tabela, as relações, as condições e a ordenação — sempre contra um alias, porque a mesma
 * entidade pode aparecer várias vezes numa consulta (como raiz e dentro de subconsultas).
 *
 * @template E of object
 * @template C of object
 * @implements Repository<E, C>
 */
abstract class AbstractRepository implements Repository
{
    public function __construct(protected readonly Database $db)
    {
    }

    /** @return JsonQuery<E, C> */
    abstract public function query(): JsonQuery;

    /**
     * Outro repositório, achado no `Beans` pela interface e devolvido com o tipo concreto - para
     * o método factory de quem precisa dele (relações da `JsonQuery`, escritas em cascata):
     *
     *     private function userRepository(): UserRepositoryImpl
     *     {
     *         return $this->userRepository ??= self::repositoryOf(UserRepository::class, UserRepositoryImpl::class);
     *     }
     *
     * A interface do domínio não expõe a `JsonQuery` (o domínio não sabe de SQL); o tipo concreto,
     * sim, e com ele o PHPStan confere a entidade do alvo de cada relação.
     *
     * @template R of object
     * @param class-string    $type interface do repositório (ex.: `UserRepository::class`)
     * @param class-string<R> $impl implementação esperada (ex.: `UserRepositoryImpl::class`)
     * @return R
     */
    protected static function repositoryOf(string $type, string $impl): object
    {
        $repository = Beans::of($type);
        if (!$repository instanceof $impl) {
            throw new LogicException(get_class($repository) . " (registrado para {$type}) não é um {$impl}");
        }

        return $repository;
    }

    abstract protected function sequence(): string;

    /**
     * WHERE do critério, contra o alias dado. Subconsultas (EXISTS) pedem aliases novos ao $ctx.
     *
     * @param C $criteria
     */
    abstract protected function conditions(object $criteria, string $alias, QueryContext $ctx): ?Sql;

    /**
     * @param C|null $criteria
     * @return string lista de ordenação ("p1.PRICE ASC, p1.ID ASC") ou "" — sem o "ORDER BY"
     */
    abstract protected function orderBy(?object $criteria, string $alias): string;

    /**
     * @param C $criteria
     * @return E|null
     */
    abstract protected function projectionOf(object $criteria): ?object;

    /**
     * @param non-empty-list<int> $ids
     * @param E                   $projection
     * @return C
     */
    abstract protected function criteriaForIds(array $ids, object $projection): object;

    /** @return Table<E> */
    protected function table(): Table
    {
        return $this->query()->table;
    }

    // :: Repository

    public function count(object $criteria): int
    {
        $ctx = new QueryContext();
        $alias = $this->query()->newAlias($ctx);
        [$where, $params] = Sql::where($this->conditions($criteria, $alias, $ctx));

        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM {$this->table()->name} {$alias}{$where}", $params);
    }

    public function fetch(object $criteria, int $offset = 0, int $limit = 0): array
    {
        $query = $this->query();
        $projection = $this->projectionOf($criteria) ?? $this->newProjection();
        $select = $query->select($projection, $criteria, $offset, $limit, new QueryContext());

        $beans = [];
        foreach ($this->db->fetchAll($select->sql, $select->params) as $row) {
            if (is_string($row['json'] ?? null)) {
                $beans[] = $query->parse($row['json']);
            }
        }

        return $beans;
    }

    public function fetchPage(object $criteria, int $page, int $pageSize): Page
    {
        $total = $this->count($criteria);
        $items = $this->fetch($criteria, $page * $pageSize, $pageSize);

        return Page::of($items, $page, $pageSize, $total);
    }

    public function fetchById(?int $id, ?object $projection = null): ?object
    {
        if ($id === null) {
            return null;
        }

        return $this->fetch($this->criteriaForIds([$id], $projection ?? $this->newProjection()), 0, 1)[0] ?? null;
    }

    public function insertOrUpdate(object $newBean, ?object $oldBean): bool
    {
        return $oldBean === null ? $this->insert($newBean) : $this->update($newBean, $oldBean);
    }

    /**
     * Campo entra no UPDATE se está na projeção E (não há old OU o valor mudou).
     *
     * @template B of object
     * @param B                 $newBean
     * @param B|null            $oldBean
     * @param B                 $projection
     * @param Closure(B): mixed $getter
     */
    public static function changed(object $newBean, ?object $oldBean, object $projection, Closure $getter): bool
    {
        if ($getter($projection) === null) {
            return false;
        }

        return $oldBean === null || !self::sameValue($getter($newBean), $getter($oldBean));
    }

    // :: Apoio às subclasses

    /**
     * DELETE com o critério (contra um alias, como as leituras).
     *
     * @param C $criteria
     */
    protected function deleteWhere(object $criteria): int
    {
        $ctx = new QueryContext();
        $alias = $this->query()->newAlias($ctx);
        [$where, $params] = Sql::where($this->conditions($criteria, $alias, $ctx));

        return $this->db->execute("DELETE FROM {$this->table()->name} AS {$alias}{$where}", $params);
    }

    /**
     * INSERT dos campos não-null, gerando o id pela sequência se preciso.
     *
     * @param E                    $bean
     * @param array<string, mixed> $foreignKeys coluna → valor
     */
    protected function insertBean(object $bean, array $foreignKeys = []): bool
    {
        $table = $this->table();
        $idColumn = $table->id();
        if ($idColumn->read($bean) === null) {
            ($idColumn->set)($bean, $this->db->nextval($this->sequence()));
        }

        $values = [];
        foreach ($table->columns as $column) {
            $value = $column->read($bean);
            if ($value !== null) {
                $values[$column->column] = $column->type->toDb($value);
            }
        }
        foreach ($foreignKeys as $column => $value) {
            if ($value !== null) {
                $values[$column] = $value;
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table->name,
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?')),
        );

        return $this->db->execute($sql, array_values($values)) > 0;
    }

    /**
     * UPDATE só dos campos projetados e alterados. Sem mudança ⇒ false, sem SQL.
     *
     * @param E                                                   $newBean
     * @param E|null                                              $oldBean
     * @param E|null                                              $projection
     * @param array<string, array{Closure(E): mixed, ColumnType}> $foreignKeys coluna → [getter, tipo]
     */
    protected function updateBean(object $newBean, ?object $oldBean, ?object $projection, array $foreignKeys = []): bool
    {
        $table = $this->table();
        $id = $table->id()->read($newBean);
        if ($id === null) {
            throw new LogicException('Missing primary key');
        }
        $projection ??= $this->newProjection();

        $sets = [];
        $params = [];
        foreach (array_slice($table->columns, 1) as $column) {
            if (self::changed($newBean, $oldBean, $projection, $column->get)) {
                $sets[] = "{$column->column} = ?";
                $params[] = $column->type->toDb($column->read($newBean));
            }
        }
        foreach ($foreignKeys as $column => [$getter, $type]) {
            if (self::changed($newBean, $oldBean, $projection, $getter)) {
                $sets[] = "{$column} = ?";
                $params[] = $type->toDb($getter($newBean));
            }
        }
        if ($sets === []) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE {$table->name} SET " . implode(', ', $sets) . " WHERE {$table->id()->column} = ?";

        return $this->db->execute($sql, $params) > 0;
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return $a == $b;   // == em datas compara o instante; === compararia a identidade do objeto
        }

        return $a === $b;
    }
}
