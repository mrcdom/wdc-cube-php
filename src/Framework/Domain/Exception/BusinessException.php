<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * Erro de regra de negócio. Em PHP não há checked exceptions: tudo é "unchecked".
 */
class BusinessException extends RuntimeException
{
    public static function wrap(string $message, Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }

        return new self($message, 0, $e);   // PHP guarda a causa em "previous"
    }
}
