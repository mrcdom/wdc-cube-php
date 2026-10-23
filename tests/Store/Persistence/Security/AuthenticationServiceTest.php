<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Persistence\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Domain\Security\PasswordUtil;
use Wdc\Store\Domain\User\User;
use Wdc\Store\Domain\User\UserRepository;
use Wdc\Store\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Store\Persistence\Security\JwtUtil;
use Wdc\Store\Persistence\StoreSchema as Seed;
use Wdc\Tests\Store\Persistence\RepositoryTestCase;

final class AuthenticationServiceTest extends RepositoryTestCase
{
    private const string SECRET = 'test-secret';

    private DateTimeImmutable $now;

    private AuthenticationServiceImpl $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new DateTimeImmutable('2026-10-22 12:00:00');
        $this->auth = new AuthenticationServiceImpl(self::SECRET, clock: fn () => $this->now);
    }

    /** What a client does: password hash + HMAC over (login + nonce). */
    private static function digest(string $user, string $password, string $nonce): string
    {
        return PasswordUtil::computeHmac(PasswordUtil::hashPassword($password), $user . $nonce);
    }

    #[Test]
    public function login_withTheRightDigest_opensASession(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $result = $this->auth->login('john', self::digest('john', 'john', $nonce), $nonce);

        self::assertNotNull($result);
        self::assertSame(Seed::JOHN_ID, $result->userId);
        $context = $this->auth->resolveToken($result->accessToken);
        self::assertNotNull($context);
        self::assertSame('john', $context->userName());
        self::assertTrue($context->hasPermission('purchase', 'write'), 'CUSTOMER can buy');
        self::assertFalse($context->hasDataAll(), 'CUSTOMER sees only their own data');
    }

    #[Test]
    public function login_withNegativeHash_works(): void
    {
        // the hash of "shopping" is a negative base-36 integer ("-4k4g..."): it must match as-is
        $users = Beans::of(UserRepository::class);
        $jane = new User()->withId(Seed::JANE_ID)->withPassword(PasswordUtil::hashPassword('shopping'));
        self::assertTrue($users->update($jane, null, new User()->withId(PV::INT)->withPassword(PV::STR)));

        $nonce = $this->auth->challenge()->nonce;
        self::assertNotNull($this->auth->login('jane', self::digest('jane', 'shopping', $nonce), $nonce));
    }

    #[Test]
    public function login_wrongPassword_isRejected(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        self::assertNull($this->auth->login('admin', self::digest('admin', 'wrong', $nonce), $nonce));
    }

    #[Test]
    public function nonce_isSingleUse_evenWhenTheLoginFails(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $this->auth->login('admin', 'wrong-digest', $nonce);

        self::assertNull($this->auth->login('admin', self::digest('admin', 'admin', $nonce), $nonce), 'replay rejected');
    }

    #[Test]
    public function nonce_expiresAfterSixtySeconds(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $this->now = $this->now->modify('+61 seconds');

        self::assertNull($this->auth->login('admin', self::digest('admin', 'admin', $nonce), $nonce));
    }

    #[Test]
    public function accessToken_expiresAfterThirtyMinutes(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $result = $this->auth->login('admin', self::digest('admin', 'admin', $nonce), $nonce);
        self::assertNotNull($result);
        $token = $result->accessToken;

        $this->now = $this->now->modify('+29 minutes');
        self::assertNotNull($this->auth->resolveToken($token));
        $this->now = $this->now->modify('+2 minutes');
        self::assertNull($this->auth->resolveToken($token));
    }

    #[Test]
    public function jwt_tampered_isRejected(): void
    {
        $token = JwtUtil::create(Seed::JOHN_ID, 'john', 60, self::SECRET, $this->now);
        [$header, , $signature] = explode('.', $token);
        $forged = $header . '.' . rtrim(strtr(base64_encode('{"sub":0,"usr":"admin","iat":0,"exp":9999999999}'), '+/', '-_'), '=') . '.' . $signature;

        self::assertNotNull(JwtUtil::validate($token, self::SECRET, $this->now));
        self::assertNull(JwtUtil::validate($forged, self::SECRET, $this->now), 'payload swapped');
        self::assertNull(JwtUtil::validate($token, 'other-secret', $this->now), 'wrong secret');
    }

    #[Test]
    public function persistentToken_opensANewSession_whileTheUserExists(): void
    {
        $token = $this->auth->createPersistentToken(Seed::JANE_ID, 'jane');
        self::assertSame(Seed::JANE_ID, $this->auth->loginWithPersistentToken($token)?->userId);

        $this->now = $this->now->modify('+31 days');
        self::assertNull($this->auth->loginWithPersistentToken($token), 'valid for 30 days');
    }

    #[Test]
    public function refresh_rotatesTheToken_andLogoutEndsTheSession(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $first = $this->auth->login('admin', self::digest('admin', 'admin', $nonce), $nonce);
        self::assertNotNull($first);

        $second = $this->auth->refresh($first->refreshToken);
        self::assertNotNull($second);
        self::assertNotSame($first->refreshToken, $second->refreshToken);
        self::assertNull($this->auth->refresh($first->refreshToken), 'the old one is no longer valid');

        $this->auth->logout($second->refreshToken);
        self::assertNull($this->auth->resolveToken($second->accessToken), 'without a session, the JWT alone is not enough');
    }

    #[Test]
    public function sessionPublicKey_isAValidX509Der(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $result = $this->auth->login('admin', self::digest('admin', 'admin', $nonce), $nonce);
        self::assertNotNull($result);

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($result->publicKey(), 64) . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        self::assertNotFalse($key);
        self::assertSame(2048, openssl_pkey_get_details($key)['bits'] ?? null);
    }
}
