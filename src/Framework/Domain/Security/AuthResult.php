<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use DateTimeImmutable;

/**
 * Result of a successful login.
 *
 * `publicKey()` is a method (not a field) so that the session's RSA key pair
 * is generated only if someone actually asks for the key — currently only the REST API.
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

    /** The session's RSA public key, X.509 DER in Base64. */
    public function publicKey(): string
    {
        return $this->session->publicKeyBase64();
    }
}
