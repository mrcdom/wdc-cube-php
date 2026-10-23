<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Transaction;

use RuntimeException;

/** Transaction infrastructure failure: unknown or expired txId, concurrent use, database error. */
class TransactionSystemException extends RuntimeException
{
}
