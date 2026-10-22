<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Repository;

use LogicException;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Persistence\AbstractRepository;
use Wdc\Framework\Persistence\Column;
use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Sql;
use Wdc\Framework\Persistence\CriterionTranslator as CT;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonQuery;
use Wdc\Framework\Persistence\Json\QueryContext;
use Wdc\Framework\Persistence\Table;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Domain\User\UserCriteria;
use Wdc\Shopping\Domain\User\UserOrderBy;
use Wdc\Shopping\Domain\User\UserRepository;

/**
 * @extends AbstractRepository<User, UserCriteria>
 */
final class UserRepositoryImpl extends AbstractRepository implements UserRepository
{
    /** @var JsonQuery<User, UserCriteria> */
    private readonly JsonQuery $query;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $table = new Table('EN_USER', static fn (): User => new User(), [
            new Column('id', 'ID', ColumnType::Int, static fn (User $u) => $u->id(), static fn (User $u, mixed $v) => $u->withId($v)),
            new Column('userName', 'USERNAME', ColumnType::Text, static fn (User $u) => $u->userName(), static fn (User $u, mixed $v) => $u->withUserName($v)),
            new Column('password', 'PASSWORD', ColumnType::Text, static fn (User $u) => $u->password(), static fn (User $u, mixed $v) => $u->withPassword($v === null ? null : trim($v))),
            new Column('name', 'NAME', ColumnType::Text, static fn (User $u) => $u->name(), static fn (User $u, mixed $v) => $u->withName($v)),
            new Column('roles', 'ROLES', ColumnType::Text, static fn (User $u) => $u->roles(), static fn (User $u, mixed $v) => $u->withRoles($v)),
        ]);
        $this->query = new JsonQuery($table, UserCriteria::class, 'u', $db->dialect())
            ->withConditions($this->conditions(...))
            ->withOrdering($this->orderBy(...));
    }

    public function query(): JsonQuery
    {
        return $this->query;
    }

    public function newProjection(): object
    {
        return new User()
            ->withId(PV::INT)
            ->withUserName(PV::STR)
            ->withPassword(PV::STR)
            ->withName(PV::STR)
            ->withRoles(PV::STR);
    }

    public function insert(object $bean): bool
    {
        return $this->insertBean($bean);
    }

    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool
    {
        return $this->updateBean($newBean, $oldBean, $projection);
    }

    public function delete(object $criteria): int
    {
        if (!$criteria->hasUserId()) {
            throw new LogicException('User delete requires userId (proteção contra DELETE sem WHERE)');
        }
        return $this->deleteWhere($criteria);
    }

    protected function sequence(): string
    {
        return 'SQ_USER';
    }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        return Sql::and([
            CT::translate("{$a}.ID", $criteria->userId()),
            CT::translate("{$a}.USERNAME", $criteria->userName()),
            CT::translate("{$a}.NAME", $criteria->name()),
            CT::translate("{$a}.ROLES", $criteria->roles()),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        return match ($criteria?->orderBy()) {
            null => '',
            UserOrderBy::OLDEST_FIRST => "{$a}.ID ASC",
            UserOrderBy::NEWEST_FIRST => "{$a}.ID DESC",
            UserOrderBy::NAME_A_TO_Z => "{$a}.NAME ASC, {$a}.ID ASC",
            UserOrderBy::LOGIN_A_TO_Z => "{$a}.USERNAME ASC, {$a}.ID ASC",
        };
    }

    protected function projectionOf(object $criteria): ?object
    {
        return $criteria->projection();
    }

    protected function criteriaForIds(array $ids, object $projection): object
    {
        return new UserCriteria()->userId()->in($ids)->withProjection($projection);
    }
}
