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
    /*
     * Nomes da tabela, da sequência e das colunas - este repositório é o dono deles. São
     * públicos porque outros repositórios também os usam (EXISTS, DELETE em cascata).
     */
    public const string TABLE = 'EN_USER';
    public const string SEQUENCE = 'SQ_USER';
    public const string ID = 'ID';
    public const string USERNAME = 'USERNAME';
    public const string PASSWORD = 'PASSWORD';
    public const string NAME = 'NAME';
    public const string ROLES = 'ROLES';

    /** @var JsonQuery<User, UserCriteria> */
    private readonly JsonQuery $query;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $table = new Table(self::TABLE, static fn (): User => new User(), [
            new Column('id', self::ID, ColumnType::Int, static fn (User $u) => $u->id(), static fn (User $u, mixed $v) => $u->withId($v)),
            new Column('userName', self::USERNAME, ColumnType::Text, static fn (User $u) => $u->userName(), static fn (User $u, mixed $v) => $u->withUserName($v)),
            new Column('password', self::PASSWORD, ColumnType::Text, static fn (User $u) => $u->password(), static fn (User $u, mixed $v) => $u->withPassword($v === null ? null : trim($v))),
            new Column('name', self::NAME, ColumnType::Text, static fn (User $u) => $u->name(), static fn (User $u, mixed $v) => $u->withName($v)),
            new Column('roles', self::ROLES, ColumnType::Text, static fn (User $u) => $u->roles(), static fn (User $u, mixed $v) => $u->withRoles($v)),
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
        return self::SEQUENCE;
    }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        $c = Sql::alias($a);

        return Sql::and([
            CT::translate($c(self::ID), $criteria->userId()),
            CT::translate($c(self::USERNAME), $criteria->userName()),
            CT::translate($c(self::NAME), $criteria->name()),
            CT::translate($c(self::ROLES), $criteria->roles()),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        $c = Sql::alias($a);

        return match ($criteria?->orderBy()) {
            null => '',
            UserOrderBy::OLDEST_FIRST => "{$c(self::ID)} ASC",
            UserOrderBy::NEWEST_FIRST => "{$c(self::ID)} DESC",
            UserOrderBy::NAME_A_TO_Z => "{$c(self::NAME)} ASC, {$c(self::ID)} ASC",
            UserOrderBy::LOGIN_A_TO_Z => "{$c(self::USERNAME)} ASC, {$c(self::ID)} ASC",
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
