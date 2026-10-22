<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Exception;

use RuntimeException;

/**
 * Conflito de estado transacional (equivale a HTTP 409): escrita sem `X-Tx-Id` com transação
 * remota aberta, ou commit de uma transação já revertida (e vice-versa).
 */
class TransactionConflictException extends RuntimeException
{
}
