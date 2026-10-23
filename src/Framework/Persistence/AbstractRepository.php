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
 * Base class for SQL repositories. All reads go through the JsonQuery (a JSON projection, one
 * database round trip for the whole graph); writes go field by field through the Table. Each
 * subclass describes the table, the relations, the conditions and the ordering — always against
 * an alias, because the same entity may appear several times in one query (as the root and
 * inside subqueries).
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
     * Another repository, looked up in `Beans` by its interface and returned with its concrete
     * type - for the factory method of whoever needs it (`JsonQuery` relations, cascading writes):
     *
     *     private function userRepository(): UserRepositoryImpl
     *     {
     *         return $this->userRepository ??= self::repositoryOf(UserRepository::class, UserRepositoryImpl::class);
     *     }
     *
     * The domain interface does not expose the `JsonQuery` (the domain knows nothing about SQL);
     * the concrete type does, and with it PHPStan checks the target entity of each relation.
     *
     * @template R of object
     * @param class-string    $type repository interface (e.g. `UserRepository::class`)
     * @param class-string<R> $impl expected implementation (e.g. `UserRepositoryImpl::class`)
     * @return R
     */
    protected static function repositoryOf(string $type, string $impl): object
    {
        $repository = Beans::of($type);
        if (!$repository instanceof $impl) {
            throw new LogicException(get_class($repository) . " (registered for {$type}) is not a {$impl}");
        }

        return $repository;
    }

    abstract protected function sequence(): string;

    /**
     * WHERE clause for the criteria, against the given alias. Subqueries (EXISTS) ask $ctx for new aliases.
     *
     * @param C $criteria
     */
    abstract protected function conditions(object $criteria, string $alias, QueryContext $ctx): ?Sql;

    /**
     * @param C|null $criteria
     * @return string ordering list ("p1.PRICE ASC, p1.ID ASC") or "" — without the "ORDER BY"
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
     * A field goes into the UPDATE if it is in the projection AND (there is no old bean OR the value changed).
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

    // :: Subclass helpers

    /**
     * DELETE by criteria (against an alias, like the reads).
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
     * INSERT of the non-null fields, generating the id from the sequence if needed.
     *
     * @param E                    $bean
     * @param array<string, mixed> $foreignKeys column → value
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
     * UPDATE of only the projected and changed fields. No change ⇒ false, no SQL.
     *
     * @param E                                                   $newBean
     * @param E|null                                              $oldBean
     * @param E|null                                              $projection
     * @param array<string, array{Closure(E): mixed, ColumnType}> $foreignKeys column → [getter, type]
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
            return $a == $b;   // == on dates compares the instant; === would compare object identity
        }

        return $a === $b;
    }
}
