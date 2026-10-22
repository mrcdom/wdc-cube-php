<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Security;

use DateTimeImmutable;
use LogicException;
use OpenSSLAsymmetricKey;
use Wdc\Framework\Domain\Security\PermissionModel;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * Uma sessão autenticada.
 *
 * O par RSA da sessão (2048 bits) só serve à API REST, que manda senhas cifradas com a chave
 * pública. Ele é gerado na primeira vez que alguém o pede, e não a cada login — gerar
 * RSA custa dezenas de milissegundos e travaria o event loop em todo login pelo WebSocket.
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
                throw new LogicException('Falha ao gerar o par RSA da sessão: ' . openssl_error_string());
            }
            $this->privateKey = $key;
        }

        return $this->privateKey;
    }

    public function publicKeyBase64(): string
    {
        $details = openssl_pkey_get_details($this->privateKey());
        $pem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

        // PEM = Base64 do DER entre "-----BEGIN PUBLIC KEY-----" e "-----END...": tirando as
        // linhas de moldura e as quebras sobra exatamente o DER (X.509 SubjectPublicKeyInfo) em Base64.
        return preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '';
    }
}
