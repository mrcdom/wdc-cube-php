<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

use Amp\Postgres\PostgresConnection;
use Amp\Postgres\PostgresTransaction;

/** @internal an open remote transaction */
final class RemoteTransactionEntry
{
    public bool $inUse = false;

    public float $lastUsedAt;

    public function __construct(
        public readonly PostgresConnection $connection,
        public readonly PostgresTransaction $transaction,
        public readonly ?string $owner,
        public readonly float $createdAt,
    ) {
        $this->lastUsedAt = $createdAt;
    }
}
