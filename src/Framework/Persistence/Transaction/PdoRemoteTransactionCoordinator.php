<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Wdc\Framework\Commons\Lang\Uuid;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Exception\TransactionConflictException;
use Wdc\Framework\Domain\Exception\TransactionLimitExceededException;
use Wdc\Framework\Persistence\Database;

/**
 * Coordenador de transação remota sobre PDO.
 *
 * Cada txId guarda uma **conexão própria**, aberta com `beginTransaction()` e mantida viva entre
 * requisições. `resume()` a liga ao {@see Database} — todo SQL da requisição vai para ela — e
 * `suspend()` volta à conexão principal. Não há threads, mas o SQL é síncrono: entre resume e suspend nenhuma outra
 * Fiber roda, então nenhuma outra sessão escreve sem querer dentro desta transação.
 *
 * Transações ociosas ou velhas demais são revertidas na varredura feita a cada `begin()`.
 */
final class PdoRemoteTransactionCoordinator implements RemoteTransactionCoordinator
{
    /** @var array<string, RemoteTransactionEntry> txId → transação aberta */
    private array $open = [];

    /** @var array<string, int> dono → nº de transações abertas (donos null não entram) */
    private array $openByOwner = [];

    /** @var array<string, array{committed: bool, owner: ?string, at: float}> desfechos recentes */
    private array $outcomes = [];

    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock relógio em milissegundos (testes)
     */
    public function __construct(
        private readonly Database $db,
        private readonly RemoteTransactionOptions $options = new RemoteTransactionOptions(),
        ?Closure $clock = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true) * 1000;
    }

    public function begin(?string $ownerKey): string
    {
        $this->reapExpired();   // libera as expiradas antes de avaliar os tetos
        $this->enforceLimits($ownerKey);
        try {
            $pdo = $this->db->newConnection();
            $pdo->beginTransaction();
        } catch (Throwable $e) {
            throw new TransactionSystemException('Falha ao abrir transação remota: ' . $e->getMessage(), 0, $e);
        }
        $txId = Uuid::v4();
        $this->open[$txId] = new RemoteTransactionEntry($pdo, $ownerKey, $this->now());
        if ($ownerKey !== null) {
            $this->openByOwner[$ownerKey] = ($this->openByOwner[$ownerKey] ?? 0) + 1;
        }
        $this->logger->debug("tx remota aberta: {$txId} (dono={$ownerKey}, abertas=" . count($this->open) . ')');

        return $txId;
    }

    public function resume(string $txId, ?string $ownerKey): void
    {
        $entry = $this->open[$txId] ?? throw new TransactionSystemException("Transação remota desconhecida ou expirada: {$txId}");
        self::verifyOwner($entry->owner, $ownerKey);
        if ($entry->inUse) {
            throw new TransactionSystemException("Transação remota em uso concorrente: {$txId}");
        }
        $entry->inUse = true;
        $entry->lastUsedAt = $this->now();
        $this->db->bind($entry->pdo);
    }

    public function suspend(string $txId): void
    {
        $entry = $this->open[$txId] ?? null;
        if ($entry === null) {
            return;
        }
        if ($this->db->pdo() === $entry->pdo) {
            $this->db->bind(null);
        }
        $entry->lastUsedAt = $this->now();
        $entry->inUse = false;
    }

    public function commit(string $txId, ?string $ownerKey): void
    {
        $this->finish($txId, $ownerKey, false);
    }

    public function rollback(string $txId, ?string $ownerKey): void
    {
        $this->finish($txId, $ownerKey, true);
    }

    public function status(string $txId, ?string $ownerKey): string
    {
        $entry = $this->open[$txId] ?? null;
        if ($entry !== null) {
            self::verifyOwner($entry->owner, $ownerKey);

            return 'open';
        }
        $prior = $this->outcomes[$txId] ?? null;
        if ($prior !== null) {
            self::verifyOwner($prior['owner'], $ownerKey);

            return $prior['committed'] ? 'committed' : 'rolledback';
        }

        return 'unknown';
    }

    public function exists(?string $txId): bool
    {
        return $txId !== null && isset($this->open[$txId]);
    }

    public function hasOpenTransactionForOwner(?string $ownerKey): bool
    {
        return $ownerKey !== null && isset($this->openByOwner[$ownerKey]);
    }

    /** Quantas estão abertas (observabilidade e testes). */
    public function openCount(): int
    {
        return count($this->open);
    }

    private function finish(string $txId, ?string $ownerKey, bool $rollback): void
    {
        $entry = $this->open[$txId] ?? null;
        if ($entry === null) {
            $this->finishAlreadyFinalized($txId, $ownerKey, $rollback);

            return;
        }
        self::verifyOwner($entry->owner, $ownerKey);
        $this->forget($txId, $entry);
        try {
            $rollback ? $entry->pdo->rollBack() : $entry->pdo->commit();
        } catch (Throwable $e) {
            throw new TransactionSystemException("Falha ao finalizar transação remota {$txId}: " . $e->getMessage(), 0, $e);
        }
        $this->outcomes[$txId] = ['committed' => !$rollback, 'owner' => $entry->owner, 'at' => $this->now()];
        $this->logger->debug('tx remota finalizada (' . ($rollback ? 'rollback' : 'commit') . "): {$txId}");
    }

    /** Retry: a transação já não está aberta. Mesmo desfecho = no-op; o oposto = conflito. */
    private function finishAlreadyFinalized(string $txId, ?string $ownerKey, bool $rollback): void
    {
        $prior = $this->outcomes[$txId] ?? throw new TransactionSystemException("Transação remota desconhecida ou expirada: {$txId}");
        self::verifyOwner($prior['owner'], $ownerKey);
        if ($prior['committed'] === $rollback) {
            throw new TransactionConflictException("Transação {$txId} já finalizada como "
                . ($prior['committed'] ? 'committed' : 'rolledback') . '; não aceita o desfecho oposto');
        }
    }

    private function enforceLimits(?string $ownerKey): void
    {
        if (count($this->open) >= $this->options->maxOpen) {
            $this->logger->warning("teto GLOBAL de transações remotas atingido ({$this->options->maxOpen})");
            throw new TransactionLimitExceededException("Limite global de transações remotas abertas atingido ({$this->options->maxOpen})");
        }
        if ($ownerKey !== null && ($this->openByOwner[$ownerKey] ?? 0) >= $this->options->maxOpenPerOwner) {
            $this->logger->warning("teto POR DONO de transações remotas atingido ({$this->options->maxOpenPerOwner}): {$ownerKey}");
            throw new TransactionLimitExceededException("Limite de transações remotas abertas por dono atingido ({$this->options->maxOpenPerOwner})");
        }
    }

    private function reapExpired(): void
    {
        $now = $this->now();
        foreach ($this->open as $txId => $entry) {
            $idleExpired = $entry->lastUsedAt < $now - $this->options->idleTimeoutMs;
            $lifetimeExpired = $now - $entry->createdAt >= $this->options->maxLifetimeMs;
            if (($idleExpired || $lifetimeExpired) && !$entry->inUse) {
                $this->forget($txId, $entry);
                try {
                    $entry->pdo->rollBack();
                } catch (Throwable) {
                    // melhor esforço: a transação foi abandonada
                }
                // revertida pela varredura = rolledback (desfaz a dúvida de um retry)
                $this->outcomes[$txId] = ['committed' => false, 'owner' => $entry->owner, 'at' => $now];
                $this->logger->info('tx remota abandonada revertida (' . ($lifetimeExpired ? 'lifetime' : 'idle') . "): {$txId}");
            }
        }
        foreach ($this->outcomes as $txId => $outcome) {
            if ($outcome['at'] < $now - $this->options->outcomeRetentionMs) {
                unset($this->outcomes[$txId]);
            }
        }
    }

    private function forget(string $txId, RemoteTransactionEntry $entry): void
    {
        unset($this->open[$txId]);
        if ($this->db->pdo() === $entry->pdo) {
            $this->db->bind(null);
        }
        $owner = $entry->owner;
        if ($owner !== null && isset($this->openByOwner[$owner])) {
            if (--$this->openByOwner[$owner] <= 0) {
                unset($this->openByOwner[$owner]);
            }
        }
    }

    /** Se a transação tem dono, a operação precisa apresentar a mesma chave. */
    private static function verifyOwner(?string $owner, ?string $requesterKey): void
    {
        if ($owner !== null && $owner !== $requesterKey) {
            throw new AccessDeniedException('Transação remota não pertence ao solicitante');
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
