<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

/**
 * Permissões no formato `entidade:operação`; `entidade:*` concede todas;
 * `data:all` libera os dados de todos os usuários. Modelo allow-wins (união).
 */
final class PermissionModel
{
    public const string DATA_ALL = 'data:all';

    private function __construct()
    {
    }

    /** @param list<string> $permissions */
    public static function grants(array $permissions, string $entity, string $operation): bool
    {
        return in_array("{$entity}:{$operation}", $permissions, true)
            || in_array("{$entity}:*", $permissions, true);
    }

    /** @param list<string> $permissions */
    public static function hasDataAll(array $permissions): bool
    {
        return in_array(self::DATA_ALL, $permissions, true);
    }
}
