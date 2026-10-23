<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

use Wdc\Framework\Domain\Config\AppConfig;

/** Remote transaction coordinator settings. Times in milliseconds. */
final readonly class RemoteTransactionOptions
{
    public const int DEFAULT_IDLE_TIMEOUT_MS = 60_000;
    /** Maximum lifetime since opening: keeps a "pinging" client from holding the connection forever. */
    public const int DEFAULT_MAX_LIFETIME_MS = 600_000;
    /** How long the outcome is remembered, so repeated commit/rollback calls are idempotent. */
    public const int DEFAULT_OUTCOME_RETENTION_MS = 300_000;
    /** Each open transaction holds a connection: global limit... */
    public const int DEFAULT_MAX_OPEN = 128;
    /** ...and per owner, so one client cannot monopolize the database. */
    public const int DEFAULT_MAX_OPEN_PER_OWNER = 16;

    public function __construct(
        public int $idleTimeoutMs = self::DEFAULT_IDLE_TIMEOUT_MS,
        public int $maxLifetimeMs = self::DEFAULT_MAX_LIFETIME_MS,
        public int $outcomeRetentionMs = self::DEFAULT_OUTCOME_RETENTION_MS,
        public int $maxOpen = self::DEFAULT_MAX_OPEN,
        public int $maxOpenPerOwner = self::DEFAULT_MAX_OPEN_PER_OWNER,
    ) {
    }

    /** Reads `database.remoteTransaction.*` (times in seconds); a missing key = the default. */
    public static function fromConfig(AppConfig $config, string $prefix = ''): self
    {
        $base = $prefix . 'database.remoteTransaction.';

        return new self(
            $config->getInt($base . 'idleTimeoutSeconds', self::DEFAULT_IDLE_TIMEOUT_MS / 1000) * 1000,
            $config->getInt($base . 'maxLifetimeSeconds', self::DEFAULT_MAX_LIFETIME_MS / 1000) * 1000,
            $config->getInt($base . 'outcomeRetentionSeconds', self::DEFAULT_OUTCOME_RETENTION_MS / 1000) * 1000,
            $config->getInt($base . 'maxOpen', self::DEFAULT_MAX_OPEN),
            $config->getInt($base . 'maxOpenPerOwner', self::DEFAULT_MAX_OPEN_PER_OWNER),
        );
    }
}
