<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Dialect;

use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonDialect;

/**
 * O que muda de um banco para outro. O resto do SQL dos repositórios não depende do banco.
 */
interface SqlDialect
{
    public function name(): string;

    /** Como montar JSON em SQL (a projeção da JsonQuery). */
    public function json(): JsonDialect;

    public function nextval(Database $db, string $sequence): int;

    /** A próxima chamada a nextval devolve $next. */
    public function restartSequence(Database $db, string $sequence, int $next): void;

    /** " LIMIT n OFFSET m" (ou ""): limit 0 = sem limite, offset 0 = sem deslocamento. */
    public function limit(int $limit, int $offset): string;

    public function tableExists(Database $db, string $table): bool;
}
