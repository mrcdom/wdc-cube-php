<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

/**
 * Permissions in `entity:operation` form; `entity:*` grants all of them;
 * `data:all` unlocks every user's data. Allow-wins model (union).
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
