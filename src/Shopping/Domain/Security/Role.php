<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Security;

use Wdc\Framework\Domain\Security\PermissionModel;

/**
 * Roles and permissions. PHP enums have no
 * state, so the table lives in a `match`.
 */
enum Role: string
{
    case ADMIN = 'ADMIN';
    case CUSTOMER = 'CUSTOMER';
    case MANAGER = 'MANAGER';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::ADMIN => ['user:*', 'product:*', 'purchase:*', 'purchase-item:*', PermissionModel::DATA_ALL],
            self::CUSTOMER => ['product:read', 'purchase:read', 'purchase:write', 'purchase-item:read', 'purchase-item:write'],
            self::MANAGER => ['product:read', 'product:write', 'purchase:read', 'purchase-item:read'],
        };
    }

    /**
     * "ADMIN, CUSTOMER" → [Role::ADMIN, Role::CUSTOMER]. Unknown roles are ignored.
     *
     * @return list<Role>
     */
    public static function parse(?string $roles): array
    {
        if ($roles === null || trim($roles) === '') {
            return [];
        }
        $result = [];
        foreach (explode(',', $roles) as $name) {
            $role = self::tryFrom(trim($name));   // tryFrom: null if it does not exist (from() would throw)
            if ($role !== null && !in_array($role, $result, true)) {
                $result[] = $role;
            }
        }

        return $result;
    }

    /**
     * Union of the permissions (allow-wins).
     *
     * @param list<Role> $roles
     * @return list<string>
     */
    public static function effectivePermissions(array $roles): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn (self $r): array => $r->permissions(), $roles))));
    }
}
