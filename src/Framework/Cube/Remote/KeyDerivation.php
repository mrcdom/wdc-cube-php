<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/**
 * Deriva a chave AES da senha do handshake (PBKDF2-HMAC-SHA256, 250 mil iterações, 32 bytes).
 *
 * É CPU pura e leva ~180 ms: feita no event loop, trava todas as sessões. Por isso há duas
 * implementações — a direta (testes, scripts) e a que roda num processo à parte (servidor).
 */
interface KeyDerivation
{
    public const int ITERATIONS = 250000;

    public function derive(string $password, string $salt): string;
}
