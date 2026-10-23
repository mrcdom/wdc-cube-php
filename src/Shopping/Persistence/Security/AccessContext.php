<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Security;

use DateTimeImmutable;
use LogicException;
use OpenSSLAsymmetricKey;
use Wdc\Framework\Domain\Security\PermissionModel;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * An authenticated session.
 *
 * The session's RSA key pair (2048 bits) only serves the REST API, which sends passwords encrypted
 * with the public key. It is generated the first time someone asks for it, not on every login —
 * generating RSA costs tens of milliseconds and would stall the event loop on every WebSocket login.
 */
final class AccessContext implements SecurityContext
{
    private ?OpenSSLAsymmetricKey $privateKey = null;

    /**
     * @param list<string> $permissions
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $userName,
        private readonly array $permissions,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $refreshToken,
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function userName(): string
    {
        return $this->userName;
    }

    public function permissions(): array
    {
        return $this->permissions;
    }

    public function hasPermission(string $entity, string $operation): bool
    {
        return PermissionModel::grants($this->permissions, $entity, $operation);
    }

    public function hasDataAll(): bool
    {
        return PermissionModel::hasDataAll($this->permissions);
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function privateKey(): OpenSSLAsymmetricKey
    {
        if ($this->privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false) {
                throw new LogicException('Failed to generate the session RSA key pair: ' . openssl_error_string());
            }
            $this->privateKey = $key;
        }

        return $this->privateKey;
    }

    public function publicKeyBase64(): string
    {
        $details = openssl_pkey_get_details($this->privateKey());
        $pem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

        // PEM = Base64 of the DER between "-----BEGIN PUBLIC KEY-----" and "-----END...": removing the
        // frame lines and line breaks leaves exactly the DER (X.509 SubjectPublicKeyInfo) in Base64.
        return preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '';
    }
}
