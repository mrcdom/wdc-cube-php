<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Security;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\Scheduler;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Domain\Security\AuthResult;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\ChallengeResult;
use Wdc\Framework\Domain\Security\PasswordUtil;
use Wdc\Framework\Domain\Security\SecurityContext;
use Wdc\Shopping\Domain\Security\Role;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Domain\User\UserCriteria;
use Wdc\Shopping\Domain\User\UserRepository;

/**
 * Autenticação por desafio HMAC + JWT, com sessões em memória.
 */
final class AuthenticationServiceImpl implements AuthenticationService
{
    public const int PERSISTENT_TOKEN_TTL_SECONDS = 30 * 24 * 3600;

    private readonly AccessContextCache $cache;

    private readonly NonceStore $nonces;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock relógio injetável (testes de expiração)
     */
    private readonly UserRepository $users;

    public function __construct(
        string $jwtSecret,
        ?Scheduler $scheduler = null,
        ?Closure $clock = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->users = Beans::of(UserRepository::class);
        $this->cache = new AccessContextCache($jwtSecret);
        $this->nonces = new NonceStore();
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();

        // limpeza periódica de sessões e nonces vencidos
        $scheduler?->scheduleAtFixedRate(function (): void {
            $now = $this->now();
            $this->cache->evictExpired($now);
            $this->nonces->evictExpired($now);
        }, 600, 3600);
    }

    public function challenge(): ChallengeResult
    {
        $now = $this->now();
        $nonce = $this->nonces->generate($now);

        return new ChallengeResult($nonce, $this->nonces->expiresAt($nonce) ?? $now);
    }

    public function login(string $userName, string $digest, string $nonce): ?AuthResult
    {
        if (!$this->nonces->consume($nonce, $this->now())) {
            $this->logger->warning('Login failed: invalid or expired nonce');

            return null;
        }
        $user = $this->fetchUserForAuth($userName);
        if ($user === null || $user->id() === null || $user->password() === null) {
            $this->logger->warning("Login failed: user not found: {$userName}");

            return null;
        }
        $expected = PasswordUtil::computeHmac($user->password(), $userName . $nonce);
        if (!hash_equals($expected, $digest)) {
            $this->logger->warning("Login failed: HMAC mismatch for user: {$userName}");

            return null;
        }

        return $this->openSession($user->id(), (string) $user->userName(), $user->roles());
    }

    public function refresh(string $refreshToken): ?AuthResult
    {
        $session = $this->cache->refresh($refreshToken, $this->now());

        return $session === null ? null : $this->result($session);
    }

    public function logout(?string $refreshToken): void
    {
        if ($refreshToken !== null) {
            $this->cache->removeByRefreshToken($refreshToken);
        }
    }

    public function resolveToken(string $jwtToken): ?SecurityContext
    {
        $claims = JwtUtil::validate($jwtToken, $this->cache->jwtSecret, $this->now());

        return $claims === null ? null : $this->cache->get($claims->userId, $this->now());
    }

    public function createPersistentToken(int $userId, string $userName): string
    {
        return JwtUtil::create($userId, $userName, self::PERSISTENT_TOKEN_TTL_SECONDS, $this->cache->jwtSecret, $this->now());
    }

    public function loginWithPersistentToken(string $persistentToken): ?AuthResult
    {
        $claims = JwtUtil::validate($persistentToken, $this->cache->jwtSecret, $this->now());
        if ($claims === null) {
            return null;
        }
        // o usuário ainda existe? e quais são os papéis dele hoje?
        $user = $this->users->fetch(new UserCriteria()
            ->withUserId($claims->userId)
            ->withProjection(new User()->withId(PV::INT)->withUserName(PV::STR)->withRoles(PV::STR)), 0, 1)[0] ?? null;
        if ($user === null || $user->id() === null) {
            $this->logger->warning("Persistent token login failed: user {$claims->userId} no longer exists");

            return null;
        }

        return $this->openSession($user->id(), (string) $user->userName(), $user->roles());
    }

    private function openSession(int $userId, string $userName, ?string $roles): AuthResult
    {
        $permissions = Role::effectivePermissions(Role::parse($roles));

        return $this->result($this->cache->createSession($userId, $userName, $permissions, $this->now()));
    }

    private function result(AccessContext $session): AuthResult
    {
        $accessToken = JwtUtil::create(
            $session->userId(),
            $session->userName(),
            AccessContextCache::ACCESS_TOKEN_TTL_SECONDS,
            $this->cache->jwtSecret,
            $this->now(),
        );

        return new AuthResult(
            $session->userId(),
            $accessToken,
            $session->refreshToken,
            $session->expiresAt,
            $session,
        );
    }

    private function fetchUserForAuth(string $userName): ?User
    {
        $projection = new User()->withId(PV::INT)->withUserName(PV::STR)->withPassword(PV::STR)->withRoles(PV::STR);

        return $this->users->fetch(new UserCriteria()->withUserName($userName)->withProjection($projection), 0, 1)[0] ?? null;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
