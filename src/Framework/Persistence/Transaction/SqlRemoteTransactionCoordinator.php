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
 * Remote transaction coordinator on top of {@see Database}.
 *
 * Each txId holds a **dedicated connection**, outside the pool, with the transaction open and kept
 * alive between requests. `resume()` binds the transaction to the request's Fiber — its SQL goes
 * to the transaction — and `suspend()` unbinds it. Other requests, on their own Fibers, keep using
 * the pool: none of them writes into this transaction by accident.
 *
 * Idle or too-old transactions are rolled back by the sweep run on every `begin()`.
 */
final class SqlRemoteTransactionCoordinator implements RemoteTransactionCoordinator
{
    /** @var array<string, RemoteTransactionEntry> txId → open transaction */
    private array $open = [];

    /** @var array<string, int> owner → number of open transactions (null owners are not counted) */
    private array $openByOwner = [];

    /** @var array<string, array{committed: bool, owner: ?string, at: float}> recent outcomes */
    private array $outcomes = [];

    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock clock in milliseconds (tests)
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
        $this->reapExpired();   // release expired ones before checking the limits
        $this->enforceLimits($ownerKey);
        try {
            $connection = $this->db->newConnection();
            $transaction = $connection->beginTransaction();
        } catch (Throwable $e) {
            throw new TransactionSystemException('Failed to open remote transaction: ' . $e->getMessage(), 0, $e);
        }
        $txId = Uuid::v4();
        $this->open[$txId] = new RemoteTransactionEntry($connection, $transaction, $ownerKey, $this->now());
        if ($ownerKey !== null) {
            $this->openByOwner[$ownerKey] = ($this->openByOwner[$ownerKey] ?? 0) + 1;
        }
        $this->logger->debug("remote tx opened: {$txId} (owner={$ownerKey}, open=" . count($this->open) . ')');

        return $txId;
    }

    public function resume(string $txId, ?string $ownerKey): void
    {
        $entry = $this->open[$txId] ?? throw new TransactionSystemException("Unknown or expired remote transaction: {$txId}");
        self::verifyOwner($entry->owner, $ownerKey);
        if ($entry->inUse) {
            throw new TransactionSystemException("Remote transaction in concurrent use: {$txId}");
        }
        $entry->inUse = true;
        $entry->lastUsedAt = $this->now();
        $this->db->bind($entry->transaction);
    }

    public function suspend(string $txId): void
    {
        $entry = $this->open[$txId] ?? null;
        if ($entry === null) {
            return;
        }
        if ($this->db->boundTransaction() === $entry->transaction) {
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

    /** Rolls back and closes all open transactions (server or test shutdown). */
    public function close(): void
    {
        foreach ($this->open as $txId => $entry) {
            $this->forget($txId, $entry);
            try {
                $entry->transaction->rollback();
            } catch (Throwable) {
                // best effort
            } finally {
                $entry->connection->close();
            }
        }
    }

    /** How many are open (observability and tests). */
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
            $rollback ? $entry->transaction->rollback() : $entry->transaction->commit();
        } catch (Throwable $e) {
            throw new TransactionSystemException("Failed to finish remote transaction {$txId}: " . $e->getMessage(), 0, $e);
        } finally {
            $entry->connection->close();
        }
        $this->outcomes[$txId] = ['committed' => !$rollback, 'owner' => $entry->owner, 'at' => $this->now()];
        $this->logger->debug('remote tx finished (' . ($rollback ? 'rollback' : 'commit') . "): {$txId}");
    }

    /** Retry: the transaction is no longer open. Same outcome = no-op; the opposite = conflict. */
    private function finishAlreadyFinalized(string $txId, ?string $ownerKey, bool $rollback): void
    {
        $prior = $this->outcomes[$txId] ?? throw new TransactionSystemException("Unknown or expired remote transaction: {$txId}");
        self::verifyOwner($prior['owner'], $ownerKey);
        if ($prior['committed'] === $rollback) {
            throw new TransactionConflictException("Transaction {$txId} already finished as "
                . ($prior['committed'] ? 'committed' : 'rolledback') . '; the opposite outcome is not accepted');
        }
    }

    private function enforceLimits(?string $ownerKey): void
    {
        if (count($this->open) >= $this->options->maxOpen) {
            $this->logger->warning("GLOBAL remote transaction limit reached ({$this->options->maxOpen})");
            throw new TransactionLimitExceededException("Global limit of open remote transactions reached ({$this->options->maxOpen})");
        }
        if ($ownerKey !== null && ($this->openByOwner[$ownerKey] ?? 0) >= $this->options->maxOpenPerOwner) {
            $this->logger->warning("PER-OWNER remote transaction limit reached ({$this->options->maxOpenPerOwner}): {$ownerKey}");
            throw new TransactionLimitExceededException("Per-owner limit of open remote transactions reached ({$this->options->maxOpenPerOwner})");
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
                    $entry->transaction->rollback();
                } catch (Throwable) {
                    // best effort: the transaction was abandoned
                } finally {
                    $entry->connection->close();
                }
                // rolled back by the sweep = rolledback (resolves a retry's doubt)
                $this->outcomes[$txId] = ['committed' => false, 'owner' => $entry->owner, 'at' => $now];
                $this->logger->info('abandoned remote tx rolled back (' . ($lifetimeExpired ? 'lifetime' : 'idle') . "): {$txId}");
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
        if ($this->db->boundTransaction() === $entry->transaction) {
            $this->db->bind(null);
        }
        $owner = $entry->owner;
        if ($owner !== null && isset($this->openByOwner[$owner])) {
            if (--$this->openByOwner[$owner] <= 0) {
                unset($this->openByOwner[$owner]);
            }
        }
    }

    /** If the transaction has an owner, the operation must present the same key. */
    private static function verifyOwner(?string $owner, ?string $requesterKey): void
    {
        if ($owner !== null && $owner !== $requesterKey) {
            throw new AccessDeniedException('Remote transaction does not belong to the requester');
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
