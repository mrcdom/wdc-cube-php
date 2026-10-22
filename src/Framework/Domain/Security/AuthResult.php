<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use DateTimeImmutable;

/**
 * Resultado de um login bem-sucedido.
 *
 * `publicKey()` é um método (e não um campo) para que o par RSA da
 * sessão só seja gerado se alguém de fato pedir a chave — hoje, só a API REST.
 */
final readonly class AuthResult
{
    public function __construct(
        public int $userId,
        public string $accessToken,
        public string $refreshToken,
        public DateTimeImmutable $expiresAt,
        private SecurityContext $session,
    ) {
    }

    /** Chave pública RSA da sessão, DER X.509 em Base64. */
    public function publicKey(): string
    {
        return $this->session->publicKeyBase64();
    }
}
