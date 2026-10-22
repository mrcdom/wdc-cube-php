<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/** PBKDF2 na própria thread. Simples; trava o event loop enquanto calcula. */
final class DirectKeyDerivation implements KeyDerivation
{
    public function derive(string $password, string $salt): string
    {
        return hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 32, true);
    }
}
