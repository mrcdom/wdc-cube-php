<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Security;

use Wdc\Framework\Domain\Security\PermissionModel;

/**
 * Papéis e permissões. Enums PHP não têm
 * estado, então a tabela fica num `match`.
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
     * "ADMIN, CUSTOMER" → [Role::ADMIN, Role::CUSTOMER]. Papel desconhecido é ignorado.
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
            $role = self::tryFrom(trim($name));   // tryFrom: null se não existir (from() lançaria erro)
            if ($role !== null && !in_array($role, $result, true)) {
                $result[] = $role;
            }
        }

        return $result;
    }

    /**
     * União das permissões (allow-wins).
     *
     * @param list<Role> $roles
     * @return list<string>
     */
    public static function effectivePermissions(array $roles): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn (self $r): array => $r->permissions(), $roles))));
    }
}
