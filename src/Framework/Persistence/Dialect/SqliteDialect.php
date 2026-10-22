<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Dialect;

use PDO;
use RuntimeException;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonDialect;
use Wdc\Framework\Persistence\Json\SqliteJsonDialect;

/** SQLite 3.44+: sem SEQUENCE (emulada na tabela SQ), OFFSET exige LIMIT. */
final class SqliteDialect implements SqlDialect
{
    private readonly JsonDialect $json;

    public function __construct()
    {
        $this->json = new SqliteJsonDialect();
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function configure(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
        // LIKE sensível a maiúsculas (como no PostgreSQL); ILIKE é emulado com lower()
        $pdo->exec('PRAGMA case_sensitive_like = ON');
        // Sem espera por trava (o padrão do PDO é 60 s). Tudo roda num processo só: se uma
        // transação remota (outra conexão) segura a escrita, esperar só congelaria o event loop —
        // quem a solta é este mesmo processo. Melhor falhar na hora ("database is locked").
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 0);
    }

    public function json(): JsonDialect
    {
        return $this->json;
    }

    public function nextval(Database $db, string $sequence): int
    {
        $value = $db->fetchValue('UPDATE SQ SET NEXT = NEXT + 1 WHERE NAME = ? RETURNING NEXT - 1', [$sequence]);

        return $value === null ? throw new RuntimeException("Unknown sequence {$sequence}") : (int) $value;
    }

    public function restartSequence(Database $db, string $sequence, int $next): void
    {
        $db->execute('INSERT OR REPLACE INTO SQ (NAME, NEXT) VALUES (?, ?)', [$sequence, $next]);
    }

    public function limit(int $limit, int $offset): string
    {
        if ($limit <= 0 && $offset <= 0) {
            return '';
        }

        return ' LIMIT ' . ($limit > 0 ? $limit : -1) . ($offset > 0 ? " OFFSET {$offset}" : '');   // -1 = sem limite
    }

    public function tableExists(Database $db, string $table): bool
    {
        return $db->fetchValue("SELECT 1 FROM sqlite_master WHERE type = 'table' AND lower(name) = lower(?)", [$table]) !== null;
    }
}
