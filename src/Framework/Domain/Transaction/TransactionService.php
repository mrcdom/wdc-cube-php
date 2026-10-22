<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Transaction;

/**
 * Demarcação programática de transação, estilo CMT.
 *
 * Em PHP uma função que "não devolve nada" devolve null, então um único método genérico
 * atende tanto o trabalho sem resultado quanto o que devolve um valor.
 *
 * Por ora só REQUIRED é implementado — é o único usado (checkout).
 */
interface TransactionService
{
    /**
     * Executa $work dentro de uma transação: participa da corrente ou abre uma nova.
     * Commit no retorno normal; rollback em exceção (repropagada) ou se setRollbackOnly().
     *
     * @template T
     * @param callable(TransactionContext): T $work
     * @return T
     */
    public function required(callable $work): mixed;
}
