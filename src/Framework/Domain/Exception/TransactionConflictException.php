<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Exception;

use RuntimeException;

/**
 * Transactional state conflict (maps to HTTP 409): a write without `X-Tx-Id` while a remote
 * transaction is open, or a commit of an already rolled-back transaction (and vice versa).
 */
class TransactionConflictException extends RuntimeException
{
}
