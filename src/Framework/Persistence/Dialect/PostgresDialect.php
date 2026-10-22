<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Dialect;

use PDO;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonDialect;
use Wdc\Framework\Persistence\Json\PostgresJsonDialect;

/**
 * PostgreSQL: SEQUENCE nativa. Os nomes sem aspas (EN_PRODUCT, SQ_USER) viram minúsculos no
 * banco — por isso o SQL do projeto nunca usa aspas em identificadores.
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

    public function configure(PDO $pdo): void
    {
        $pdo->exec("SET TIME ZONE 'UTC'");
        // Espera curta por trava de linha. Tudo roda num processo só: se uma transação remota
        // (outra conexão) segura a linha, esperar muito só congelaria o event loop — quem a solta
        // é este mesmo processo, na próxima requisição do cliente dela.
        $pdo->exec("SET lock_timeout = '2s'");
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
        $db->fetchValue('SELECT setval(?, ?, false)', [strtolower($sequence), $next]);   // false: o próximo nextval devolve $next
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
