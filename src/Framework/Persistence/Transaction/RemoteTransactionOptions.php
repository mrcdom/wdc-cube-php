<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

use Wdc\Framework\Domain\Config\AppConfig;

/** Parâmetros do coordenador de transação remota. Tempos em milissegundos. */
final readonly class RemoteTransactionOptions
{
    public const int DEFAULT_IDLE_TIMEOUT_MS = 60_000;
    /** Vida máxima desde a abertura: impede que um cliente "pingando" segure a conexão para sempre. */
    public const int DEFAULT_MAX_LIFETIME_MS = 600_000;
    /** Por quanto tempo o desfecho é lembrado, para commit/rollback repetidos serem idempotentes. */
    public const int DEFAULT_OUTCOME_RETENTION_MS = 300_000;
    /** Cada transação aberta segura uma conexão: teto global... */
    public const int DEFAULT_MAX_OPEN = 128;
    /** ...e por dono, para um cliente não monopolizar o banco. */
    public const int DEFAULT_MAX_OPEN_PER_OWNER = 16;

    public function __construct(
        public int $idleTimeoutMs = self::DEFAULT_IDLE_TIMEOUT_MS,
        public int $maxLifetimeMs = self::DEFAULT_MAX_LIFETIME_MS,
        public int $outcomeRetentionMs = self::DEFAULT_OUTCOME_RETENTION_MS,
        public int $maxOpen = self::DEFAULT_MAX_OPEN,
        public int $maxOpenPerOwner = self::DEFAULT_MAX_OPEN_PER_OWNER,
    ) {
    }

    /** Lê `database.remoteTransaction.*` (tempos em segundos); chave ausente = padrão. */
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
