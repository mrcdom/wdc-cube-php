<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/**
 * Derives the AES key from the handshake password (PBKDF2-HMAC-SHA256, 250k iterations, 32 bytes).
 *
 * It is pure CPU and takes ~180 ms: done on the event loop, it blocks every session. That is why there
 * are two implementations — the direct one (tests, scripts) and one that runs in a separate process (server).
 */
interface KeyDerivation
{
    public const int ITERATIONS = 250000;

    public function derive(string $password, string $salt): string;
}
