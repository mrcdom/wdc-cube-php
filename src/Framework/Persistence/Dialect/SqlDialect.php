<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Dialect;

use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonDialect;

/**
 * What differs from one database to another. The rest of the repositories' SQL is database-independent.
 */
interface SqlDialect
{
    public function name(): string;

    /** How to build JSON in SQL (the JsonQuery projection). */
    public function json(): JsonDialect;

    public function nextval(Database $db, string $sequence): int;

    /** The next call to nextval returns $next. */
    public function restartSequence(Database $db, string $sequence, int $next): void;

    /** " LIMIT n OFFSET m" (or ""): limit 0 = no limit, offset 0 = no offset. */
    public function limit(int $limit, int $offset): string;

    public function tableExists(Database $db, string $table): bool;
}
