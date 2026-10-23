<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

/**
 * Client-driven transaction over HTTP: `begin` returns a txId; the writes that carry it
 * (`X-Tx-Id` header) all run in the same physical transaction; `commit`/`rollback` end it.
 *
 * The owner (`ownerKey`, e.g. "user:42") is fixed at begin and checked on every operation. A null
 * owner means an ownerless transaction (security disabled and client without `X-Client-Id`).
 */
interface RemoteTransactionCoordinator
{
    /**
     * @throws \Wdc\Framework\Domain\Exception\TransactionLimitExceededException limit reached
     */
    public function begin(?string $ownerKey): string;

    /** Binds the transaction to the current request: subsequent SQL runs in it. */
    public function resume(string $txId, ?string $ownerKey): void;

    /** Unbinds the transaction from the request; it stays open for the next one. */
    public function suspend(string $txId): void;

    /** Idempotent: repeating the same outcome is a no-op; the opposite outcome is a conflict (409). */
    public function commit(string $txId, ?string $ownerKey): void;

    public function rollback(string $txId, ?string $ownerKey): void;

    /** "open", "committed", "rolledback" or "unknown" — resolves the doubt left by a lost response. */
    public function status(string $txId, ?string $ownerKey): string;

    public function exists(?string $txId): bool;

    public function hasOpenTransactionForOwner(?string $ownerKey): bool;
}
