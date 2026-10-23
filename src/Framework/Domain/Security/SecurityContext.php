<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use OpenSSLAsymmetricKey;

/** Who is authenticated and what they may do. */
interface SecurityContext
{
    public function userId(): int;

    public function userName(): string;

    /** @return list<string> */
    public function permissions(): array;

    public function hasPermission(string $entity, string $operation): bool;

    public function hasDataAll(): bool;

    /** The session's RSA private key (decrypts passwords sent through the REST API). */
    public function privateKey(): OpenSSLAsymmetricKey;

    /** The session's public key, X.509 DER in Base64. */
    public function publicKeyBase64(): string;
}
