<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * A cifra do canal de uma sessão.
 *
 * O cliente sorteia uma senha, deriva dela a chave AES (PBKDF2-SHA256, 250 mil iterações) e
 * manda a senha cifrada com a RSA do handshake no `secret` = `base36(RSA(senha)).b64url(sal).b64url(iv)`.
 * O servidor faz o caminho inverso e chega à mesma chave.
 *
 * AES-256-GCM com tag de 128 bits anexada; o IV é fixo por sessão (o do handshake) — fraqueza
 * conhecida do protocolo, mantida para interoperar com o shell.
 */
final class RemoteDataSecurity
{
    public const int PBKDF2_ITERATIONS = KeyDerivation::ITERATIONS;

    private const int TAG_LENGTH = 16;

    private ?string $key = null;

    private ?string $iv = null;

    /** O último `secret` aplicado: o mesmo de novo não refaz o PBKDF2 (~180 ms de CPU). */
    private ?string $appliedSecret = null;

    public function __construct(
        private readonly RemoteAppSecurity $security,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly KeyDerivation $keyDerivation = new DirectKeyDerivation(),
    ) {
    }

    /** Erros são registrados e engolidos: a sessão segue com a chave anterior. */
    public function updateSecret(string $secret): void
    {
        if ($secret === $this->appliedSecret) {
            return;
        }
        try {
            $parts = explode('.', $secret);
            if (count($parts) !== 3) {
                throw new RuntimeException('secret malformado');
            }
            // 1. abre a senha: RSA → bytes do texto Base64 → senha
            $message = $this->security->rsa->decrypt(gmp_init($parts[0], 36));
            $password = base64_decode(gmp_export($message), true);   // gmp_export: inteiro → bytes big-endian
            if ($password === false) {
                throw new RuntimeException('senha do secret não é Base64');
            }
            // 2. sal e IV
            $salt = self::b64UrlDecode($parts[1]);
            $iv = self::b64UrlDecode($parts[2]);
            // 3. a mesma derivação do cliente (no servidor, num processo à parte)
            $this->key = $this->keyDerivation->derive($password, $salt);
            $this->iv = $iv;
            $this->appliedSecret = $secret;
        } catch (Throwable $e) {
            $this->logger->error('updateSecret', ['exception' => $e]);
        }
    }

    public function isReady(): bool
    {
        return $this->key !== null;
    }

    public function cipher(string $plain): string
    {
        [$key, $iv] = $this->material();
        $cipherText = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($cipherText === false) {
            throw new RuntimeException('Falha ao cifrar: ' . openssl_error_string());
        }

        return $cipherText . $tag;   // o WebCrypto do browser também anexa a tag no fim
    }

    public function decipher(string $data): string
    {
        [$key, $iv] = $this->material();
        if (strlen($data) < self::TAG_LENGTH) {
            throw new RuntimeException('Texto cifrado curto demais');
        }
        $plain = openssl_decrypt(
            substr($data, 0, -self::TAG_LENGTH),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            substr($data, -self::TAG_LENGTH),
        );
        if ($plain === false) {
            throw new RuntimeException('Falha ao decifrar (chave errada ou dado adulterado)');
        }

        return $plain;
    }

    public function b64Cipher(string $text): string
    {
        return base64_encode($this->cipher($text));
    }

    public function b64Decipher(string $b64Text): string
    {
        $data = base64_decode($b64Text, true);
        if ($data === false) {
            throw new RuntimeException('Texto cifrado não é Base64');
        }

        return $this->decipher($data);
    }

    /** @return array{string, string} */
    private function material(): array
    {
        if ($this->key === null || $this->iv === null) {
            throw new RuntimeException('Canal sem chave: o handshake (secret) ainda não aconteceu');
        }

        return [$this->key, $this->iv];
    }

    private static function b64UrlDecode(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);   // padding opcional
        if ($bytes === false) {
            throw new RuntimeException('Base64 URL-safe inválido');
        }

        return $bytes;
    }
}
