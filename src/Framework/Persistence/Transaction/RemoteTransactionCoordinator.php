<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

/**
 * Transação dirigida pelo cliente, por cima de HTTP: `begin` devolve um txId; as escritas que
 * o trazem (cabeçalho `X-Tx-Id`) rodam todas na mesma transação física; `commit`/`rollback`
 * a encerram.
 *
 * O dono (`ownerKey`, ex.: "user:42") é fixado no begin e conferido em toda operação. Dono
 * null = transação sem dono (segurança desligada e cliente sem `X-Client-Id`).
 */
interface RemoteTransactionCoordinator
{
    /**
     * @throws \Wdc\Framework\Domain\Exception\TransactionLimitExceededException teto atingido
     */
    public function begin(?string $ownerKey): string;

    /** Liga a transação à requisição corrente: o SQL seguinte roda nela. */
    public function resume(string $txId, ?string $ownerKey): void;

    /** Desliga a transação da requisição; ela segue aberta para a próxima. */
    public function suspend(string $txId): void;

    /** Idempotente: repetir o mesmo desfecho é no-op; o desfecho oposto dá conflito (409). */
    public function commit(string $txId, ?string $ownerKey): void;

    public function rollback(string $txId, ?string $ownerKey): void;

    /** "open", "committed", "rolledback" ou "unknown" — desfaz a dúvida de uma resposta perdida. */
    public function status(string $txId, ?string $ownerKey): string;

    public function exists(?string $txId): bool;

    public function hasOpenTransactionForOwner(?string $ownerKey): bool;
}
