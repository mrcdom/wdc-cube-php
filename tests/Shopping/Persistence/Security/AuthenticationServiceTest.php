<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Security\PasswordUtil;
use Wdc\Shopping\Domain\User\UserRepository;
use Wdc\Shopping\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Shopping\Persistence\Security\JwtUtil;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Tests\Shopping\Persistence\RepositoryTestCase;

final class AuthenticationServiceTest extends RepositoryTestCase
{
    private const string SECRET = 'segredo-de-teste';

    private DateTimeImmutable $now;

    private AuthenticationServiceImpl $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new DateTimeImmutable('2026-10-22 12:00:00');
        $this->auth = new AuthenticationServiceImpl(self::SECRET, clock: fn () => $this->now);
    }

    /** O que um cliente faz: hash da senha + HMAC sobre (login + nonce). */
    private static function digest(string $user, string $password, string $nonce): string
    {
        return PasswordUtil::computeHmac(PasswordUtil::hashPassword($password), $user . $nonce);
    }

    #[Test]
    public function login_withTheRightDigest_opensASession(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $result = $this->auth->login('fulano', self::digest('fulano', 'fulano', $nonce), $nonce);

        self::assertNotNull($result);
        self::assertSame(Seed::FULANO_ID, $result->userId);
        $context = $this->auth->resolveToken($result->accessToken);
        self::assertNotNull($context);
        self::assertSame('fulano', $context->userName());
        self::assertTrue($context->hasPermission('purchase', 'write'), 'CUSTOMER compra');
        self::assertFalse($context->hasDataAll(), 'CUSTOMER só vê os próprios dados');
    }

    #[Test]
    public function login_withNegativeHash_works(): void
    {
        // o hash de "beotrano" é um inteiro negativo em base 36 ("-17ms..."): tem de casar igual
        $nonce = $this->auth->challenge()->nonce;
        self::assertNotNull($this->auth->login('beotrano', self::digest('beotrano', 'beotrano', $nonce), $nonce));
    }

    #[Test]
    public function login_wrongPassword_isRejected(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        self::assertNull($this->auth->login('admin', self::digest('admin', 'errada', $nonce), $nonce));
    }

    #[Test]
    public function nonce_isSingleUse_evenWhenTheLoginFails(): void
    {
        $nonce = $this->auth->challenge()->nonce;
        $this->auth->login('admin', 'digest-errado', $nonce);

        self::assertNull($this->auth->login('admin', self::digest('admin', 'admin', $nonce), $nonce), 'replay recusado');
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
        $token = JwtUtil::create(Seed::FULANO_ID, 'fulano', 60, self::SECRET, $this->now);
        [$header, , $signature] = explode('.', $token);
        $forged = $header . '.' . rtrim(strtr(base64_encode('{"sub":0,"usr":"admin","iat":0,"exp":9999999999}'), '+/', '-_'), '=') . '.' . $signature;

        self::assertNotNull(JwtUtil::validate($token, self::SECRET, $this->now));
        self::assertNull(JwtUtil::validate($forged, self::SECRET, $this->now), 'payload trocado');
        self::assertNull(JwtUtil::validate($token, 'outro-segredo', $this->now), 'segredo errado');
    }

    #[Test]
    public function persistentToken_opensANewSession_whileTheUserExists(): void
    {
        $token = $this->auth->createPersistentToken(Seed::BEOTRANO_ID, 'beotrano');
        self::assertSame(Seed::BEOTRANO_ID, $this->auth->loginWithPersistentToken($token)?->userId);

        $this->now = $this->now->modify('+31 days');
        self::assertNull($this->auth->loginWithPersistentToken($token), 'vale 30 dias');
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
        self::assertNull($this->auth->refresh($first->refreshToken), 'o antigo deixou de valer');

        $this->auth->logout($second->refreshToken);
        self::assertNull($this->auth->resolveToken($second->accessToken), 'sem sessão, o JWT sozinho não basta');
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
