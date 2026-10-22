<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

use PDO;

/** @internal uma transação remota aberta */
final class RemoteTransactionEntry
{
    public bool $inUse = false;

    public float $lastUsedAt;

    public function __construct(
        public readonly PDO $pdo,
        public readonly ?string $owner,
        public readonly float $createdAt,
    ) {
        $this->lastUsedAt = $createdAt;
    }
}
