<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

use RuntimeException;

/** Falha da infraestrutura de transação: txId desconhecido ou expirado, uso concorrente, erro do banco. */
class TransactionSystemException extends RuntimeException
{
}
