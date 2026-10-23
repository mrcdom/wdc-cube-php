<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Dialect;

use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonDialect;
use Wdc\Framework\Persistence\Json\PostgresJsonDialect;

/**
 * PostgreSQL: native SEQUENCE. Unquoted names (EN_PRODUCT, SQ_USER) become lowercase in the
 * database — which is why the project's SQL never quotes identifiers.
 */
final class PostgresDialect implements SqlDialect
{
    private readonly JsonDialect $json;

    public function __construct()
    {
        $this->json = new PostgresJsonDialect();
    }

    public function name(): string
    {
        return 'pgsql';
    }

    public function json(): JsonDialect
    {
        return $this->json;
    }

    public function nextval(Database $db, string $sequence): int
    {
        return (int) $db->fetchValue('SELECT nextval(?)', [strtolower($sequence)]);
    }

    public function restartSequence(Database $db, string $sequence, int $next): void
    {
        $db->fetchValue('SELECT setval(?, ?, false)', [strtolower($sequence), $next]);   // false: the next nextval returns $next
    }

    public function limit(int $limit, int $offset): string
    {
        return ($limit > 0 ? " LIMIT {$limit}" : '') . ($offset > 0 ? " OFFSET {$offset}" : '');
    }

    public function tableExists(Database $db, string $table): bool
    {
        return $db->fetchValue('SELECT to_regclass(?) IS NOT NULL', [strtolower($table)]) === true;
    }
}
