<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence\Security;

use DateTimeImmutable;
use Wdc\Framework\Commons\Lang\Uuid;

/** In-memory single-use nonces valid for 60 s. */
final class NonceStore
{
    public const int TTL_SECONDS = 60;

    /** @var array<string, DateTimeImmutable> nonce → expires at */
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

    /** Removes the nonce (even if the login fails afterwards) and tells whether it was still valid. */
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
