<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * Business rule violation. PHP has no checked exceptions: everything is "unchecked".
 */
class BusinessException extends RuntimeException
{
    public static function wrap(string $message, Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }

        return new self($message, 0, $e);   // PHP keeps the cause in "previous"
    }
}
