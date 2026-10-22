<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use OpenSSLAsymmetricKey;

/** Quem está autenticado e o que pode fazer. */
interface SecurityContext
{
    public function userId(): int;

    public function userName(): string;

    /** @return list<string> */
    public function permissions(): array;

    public function hasPermission(string $entity, string $operation): bool;

    public function hasDataAll(): bool;

    /** Chave privada RSA da sessão (decifra senhas enviadas pela API REST). */
    public function privateKey(): OpenSSLAsymmetricKey;

    /** Chave pública da sessão, DER X.509 em Base64. */
    public function publicKeyBase64(): string;
}
