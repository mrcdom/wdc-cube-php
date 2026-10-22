<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

/**
 * Autenticação por desafio HMAC, sessões e tokens (JWT).
 *
 * Fluxo: `challenge()` dá um nonce de uso único; o cliente manda
 * `hex(HMAC-SHA256(key = hashPassword(senha), data = userName + nonce))` em `login()`.
 * A senha nunca trafega.
 */
interface AuthenticationService
{
    public function challenge(): ChallengeResult;

    public function login(string $userName, string $digest, string $nonce): ?AuthResult;

    public function refresh(string $refreshToken): ?AuthResult;

    public function logout(?string $refreshToken): void;

    public function resolveToken(string $jwtToken): ?SecurityContext;

    /** Token de longa duração para o "lembrar-me" (auto-login). */
    public function createPersistentToken(int $userId, string $userName): string;

    public function loginWithPersistentToken(string $persistentToken): ?AuthResult;
}
