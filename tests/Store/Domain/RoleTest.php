<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Domain\Security\PermissionModel;
use Wdc\Store\Domain\Security\Role;

final class RoleTest extends TestCase
{
    #[Test]
    public function parse_ignoresUnknownAndDuplicates(): void
    {
        self::assertSame([Role::ADMIN, Role::CUSTOMER], Role::parse(' ADMIN, CUSTOMER,XPTO,ADMIN '));
        self::assertSame([], Role::parse(null));
        self::assertSame([], Role::parse('  '));
    }

    #[Test]
    public function permissions_allowWins(): void
    {
        $perms = Role::effectivePermissions([Role::CUSTOMER, Role::MANAGER]);

        self::assertTrue(PermissionModel::grants($perms, 'product', 'write'), 'MANAGER grants');
        self::assertTrue(PermissionModel::grants($perms, 'purchase', 'write'), 'CUSTOMER grants');
        self::assertFalse(PermissionModel::grants($perms, 'user', 'read'));
        self::assertFalse(PermissionModel::hasDataAll($perms));
    }

    #[Test]
    public function admin_hasWildcardsAndDataAll(): void
    {
        $perms = Role::ADMIN->permissions();

        self::assertTrue(PermissionModel::grants($perms, 'user', 'delete'));
        self::assertTrue(PermissionModel::hasDataAll($perms));
    }
}
