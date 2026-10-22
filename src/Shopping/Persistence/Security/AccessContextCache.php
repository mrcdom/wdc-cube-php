<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Security;

use DateTimeImmutable;
use Wdc\Framework\Commons\Lang\Uuid;

/** Sessões em memória, indexadas pelo refresh token (um usuário pode ter várias). */
final class AccessContextCache
{
    public const int ACCESS_TOKEN_TTL_SECONDS = 30 * 60;

    public const int REFRESH_TOKEN_TTL_SECONDS = 7 * 24 * 3600;

    /** @var array<string, AccessContext> */
    private array $byRefreshToken = [];

    public function __construct(public readonly string $jwtSecret)
    {
    }

    /** @param list<string> $permissions */
    public function createSession(int $userId, string $userName, array $permissions, DateTimeImmutable $now): AccessContext
    {
        $context = new AccessContext(
            $userId,
            $userName,
            $permissions,
            $now->modify('+' . self::ACCESS_TOKEN_TTL_SECONDS . ' seconds'),
            Uuid::v4(),
        );
        $this->byRefreshToken[$context->refreshToken] = $context;

        return $context;
    }

    /** Troca o refresh token (rotação): o antigo deixa de valer. */
    public function refresh(string $refreshToken, DateTimeImmutable $now): ?AccessContext
    {
        $existing = $this->byRefreshToken[$refreshToken] ?? null;
        if ($existing === null) {
            return null;
        }
        unset($this->byRefreshToken[$refreshToken]);

        return $this->createSession($existing->userId(), $existing->userName(), $existing->permissions(), $now);
    }

    /** Qualquer sessão ainda válida do usuário. */
    public function get(int $userId, DateTimeImmutable $now): ?AccessContext
    {
        foreach ($this->byRefreshToken as $context) {
            if ($context->userId() === $userId && !$context->isExpired($now)) {
                return $context;
            }
        }

        return null;
    }

    public function removeByRefreshToken(string $refreshToken): bool
    {
        $existed = isset($this->byRefreshToken[$refreshToken]);
        unset($this->byRefreshToken[$refreshToken]);

        return $existed;
    }

    public function evictExpired(DateTimeImmutable $now): void
    {
        $limit = $now->modify('-' . self::REFRESH_TOKEN_TTL_SECONDS . ' seconds');
        $this->byRefreshToken = array_filter(
            $this->byRefreshToken,
            static fn (AccessContext $c): bool => $c->expiresAt >= $limit,
        );
    }
}
