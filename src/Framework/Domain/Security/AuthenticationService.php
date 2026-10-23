<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

/**
 * HMAC challenge authentication, sessions and tokens (JWT).
 *
 * Flow: `challenge()` hands out a single-use nonce; the client sends
 * `hex(HMAC-SHA256(key = hashPassword(password), data = userName + nonce))` to `login()`.
 * The password never travels over the wire.
 */
interface AuthenticationService
{
    public function challenge(): ChallengeResult;

    public function login(string $userName, string $digest, string $nonce): ?AuthResult;

    public function refresh(string $refreshToken): ?AuthResult;

    public function logout(?string $refreshToken): void;

    public function resolveToken(string $jwtToken): ?SecurityContext;

    /** Long-lived token for "remember me" (auto-login). */
    public function createPersistentToken(int $userId, string $userName): string;

    public function loginWithPersistentToken(string $persistentToken): ?AuthResult;
}
