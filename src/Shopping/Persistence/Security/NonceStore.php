<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Security;

use DateTimeImmutable;
use Wdc\Framework\Commons\Lang\Uuid;

/** Nonces de uso único com validade de 60 s, em memória. */
final class NonceStore
{
    public const int TTL_SECONDS = 60;

    /** @var array<string, DateTimeImmutable> nonce → expira em */
    private array $nonces = [];

    public function generate(DateTimeImmutable $now): string
    {
        $nonce = Uuid::v4();
        $this->nonces[$nonce] = $now->modify('+' . self::TTL_SECONDS . ' seconds');

        return $nonce;
    }

    public function expiresAt(string $nonce): ?DateTimeImmutable
    {
        return $this->nonces[$nonce] ?? null;
    }

    /** Remove o nonce (mesmo se o login falhar depois) e diz se ainda valia. */
    public function consume(string $nonce, DateTimeImmutable $now): bool
    {
        $expiresAt = $this->nonces[$nonce] ?? null;
        unset($this->nonces[$nonce]);

        return $expiresAt !== null && $now < $expiresAt;
    }

    public function evictExpired(DateTimeImmutable $now): void
    {
        $this->nonces = array_filter($this->nonces, static fn (DateTimeImmutable $exp): bool => $now <= $exp);
    }
}
