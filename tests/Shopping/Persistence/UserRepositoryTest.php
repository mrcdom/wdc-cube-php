<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Domain\Security\PasswordUtil;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Domain\User\UserCriteria;
use Wdc\Shopping\Domain\User\UserOrderBy;
use Wdc\Shopping\Domain\User\UserRepository;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;

/** User repository: queries, criteria, insert, update and delete over the seed. */
final class UserRepositoryTest extends RepositoryTestCase
{
    private function repo(): UserRepository
    {
        return Beans::of(UserRepository::class);
    }

    private function found(UserCriteria $criteria): int
    {
        return count($this->repo()->fetch($criteria));
    }

    /** @return list<?string> */
    private function userNamesOrderedBy(UserOrderBy $order): array
    {
        return array_map(static fn (User $u) => $u->userName(), $this->repo()->fetch(new UserCriteria()->withOrderBy($order)));
    }

    #[Test]
    public function fetchAll_returnsAllSeededUsers(): void
    {
        self::assertSame(3, $this->found(new UserCriteria()));
    }

    #[Test]
    public function fetchById_returnsCorrectUser(): void
    {
        $user = $this->repo()->fetchById(Seed::ADMIN_ID);
        self::assertNotNull($user);
        self::assertSame('admin', $user->userName());
        self::assertSame('John Smith', $user->name());
        self::assertSame(PasswordUtil::hashPassword('admin'), $user->password());
    }

    #[Test]
    public function fetchById_nonExistent_returnsNull(): void
    {
        self::assertNull($this->repo()->fetchById(PHP_INT_MAX));
    }

    #[Test]
    public function fetchWithProjection_onlyRequestedFields(): void
    {
        $user = $this->repo()->fetchById(Seed::ADMIN_ID, new User()->withId(PV::INT)->withUserName(PV::STR));
        self::assertSame('admin', $user?->userName());
        self::assertNull($user->password());
    }

    #[Test]
    public function fetchByCriteria_userName(): void
    {
        $users = $this->repo()->fetch(new UserCriteria()->withUserName('john'));
        self::assertCount(1, $users);
        self::assertSame(Seed::JOHN_ID, $users[0]->id());
    }

    #[Test]
    public function fetchWithOffsetAndLimit(): void
    {
        $users = $this->repo()->fetch(new UserCriteria()->withOrderBy(UserOrderBy::OLDEST_FIRST), 1, 1);
        self::assertCount(1, $users);
        self::assertSame(Seed::JOHN_ID, $users[0]->id());
    }

    #[Test]
    public function insert_newUser(): void
    {
        $user = new User()->withUserName('newuser')->withPassword('secret')->withName('New User');
        self::assertTrue($this->repo()->insert($user));
        self::assertSame(3, $user->id());
        self::assertSame('New User', $this->repo()->fetchById($user->id())?->name());
    }

    #[Test]
    public function update_existingUser(): void
    {
        $original = $this->repo()->fetchById(Seed::ADMIN_ID);
        self::assertNotNull($original);
        $updated = clone $original;
        $updated->withName('Changed Name');

        self::assertTrue($this->repo()->update($updated, $original));
        self::assertSame('Changed Name', $this->repo()->fetchById(Seed::ADMIN_ID)?->name());
    }

    #[Test]
    public function update_partialFields_onlyChangesSpecifiedFields(): void
    {
        $original = $this->repo()->fetchById(Seed::ADMIN_ID);
        self::assertNotNull($original);
        $updated = new User()->withId($original->id())->withName('Partial Name');

        self::assertTrue($this->repo()->update($updated, $original, new User()->withId(PV::INT)->withName(PV::STR)));

        $fetched = $this->repo()->fetchById(Seed::ADMIN_ID);
        self::assertSame('Partial Name', $fetched?->name());
        self::assertSame($original->userName(), $fetched->userName());
        self::assertSame($original->password(), $fetched->password());
    }

    #[Test]
    public function update_setFieldToNull_clearsValue(): void
    {
        $original = $this->repo()->fetchById(Seed::ADMIN_ID);
        self::assertNotNull($original);
        self::assertNotNull($original->roles());
        $updated = new User()->withId($original->id())->withRoles(null);

        self::assertTrue($this->repo()->update($updated, $original, new User()->withId(PV::INT)->withRoles(PV::STR)));

        $fetched = $this->repo()->fetchById(Seed::ADMIN_ID);
        self::assertNull($fetched?->roles());
        self::assertSame($original->name(), $fetched?->name());
    }

    #[Test]
    public function deleteByUserId_noFkDependency(): void
    {
        self::assertSame(1, $this->repo()->delete(new UserCriteria()->withUserId(Seed::JANE_ID)));
        self::assertSame(2, $this->repo()->count(new UserCriteria()));
        self::assertSame(0, $this->repo()->delete(new UserCriteria()->withUserId(PHP_INT_MAX)));
    }

    #[Test]
    public function startingWith_anchorsAtTheBeginning(): void
    {
        self::assertSame(1, $this->found(new UserCriteria()->userName()->startingWith('adm')));
        self::assertSame(0, $this->found(new UserCriteria()->userName()->startingWith('dmi')));
    }

    #[Test]
    public function containing_matchesAnywhere(): void
    {
        $users = $this->repo()->fetch(new UserCriteria()->userName()->containing('oh'));
        self::assertCount(1, $users);
        self::assertSame('john', $users[0]->userName());
    }

    #[Test]
    public function ilike_ignoresCase(): void
    {
        self::assertSame(1, $this->found(new UserCriteria()->userName()->ilike('ADMIN')));
    }

    #[Test]
    public function like_doesNotIgnoreCase(): void
    {
        self::assertSame(0, $this->found(new UserCriteria()->userName()->like('ADMIN')));
        self::assertSame(1, $this->found(new UserCriteria()->userName()->like('admin')));
    }

    #[Test]
    public function wildcardsBelongToTheValue(): void
    {
        self::assertSame(0, $this->found(new UserCriteria()->userName()->like('dmi')));
        self::assertSame(1, $this->found(new UserCriteria()->userName()->like('%dmi%')));
    }

    #[Test]
    public function or_acrossTextRequests(): void
    {
        $criteria = new UserCriteria();
        $criteria->userName()->or()->eq('admin');
        $criteria->userName()->eq('john');
        self::assertSame(2, $this->found($criteria));
    }

    #[Test]
    public function emptyText_doesNotFilter(): void
    {
        $criteria = new UserCriteria();
        $criteria->userName()->startingWith('');
        self::assertSame(3, $this->found($criteria), 'empty text adds no request');
    }

    #[Test]
    public function filterByNameAndRoles(): void
    {
        self::assertSame(1, $this->found(new UserCriteria()->name()->containing('Smith')));
        self::assertSame(1, $this->found(new UserCriteria()->roles()->eq('ADMIN')));
        self::assertSame(2, $this->found(new UserCriteria()->roles()->eq('CUSTOMER')));
        self::assertSame(1, $this->found(new UserCriteria()->roles()->containing('ADMIN')));
    }

    #[Test]
    public function orderBy_loginAToZ_differsFromName(): void
    {
        $byLogin = $this->userNamesOrderedBy(UserOrderBy::LOGIN_A_TO_Z);
        self::assertSame(['admin', 'jane', 'john'], $byLogin);
        self::assertSame(['jane', 'john', 'admin'], $this->userNamesOrderedBy(UserOrderBy::NAME_A_TO_Z));
    }
}
