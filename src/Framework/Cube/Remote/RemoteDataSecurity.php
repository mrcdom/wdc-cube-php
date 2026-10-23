<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The channel encryption of a session.
 *
 * The client picks a random password, derives the AES key from it (PBKDF2-SHA256, 250k iterations) and
 * sends the password encrypted with the handshake RSA key in `secret` = `base36(RSA(password)).b64url(salt).b64url(iv)`.
 * The server goes the reverse way and arrives at the same key.
 *
 * AES-256-GCM with an appended 128-bit tag; the IV is fixed per session (the handshake one) — a known
 * weakness of the protocol, kept to interoperate with the shell.
 */
final class RemoteDataSecurity
{
    public const int PBKDF2_ITERATIONS = KeyDerivation::ITERATIONS;

    private const int TAG_LENGTH = 16;

    private ?string $key = null;

    private ?string $iv = null;

    /** The last applied `secret`: the same one again does not redo the PBKDF2 (~180 ms of CPU). */
    private ?string $appliedSecret = null;

    public function __construct(
        private readonly RemoteAppSecurity $security,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly KeyDerivation $keyDerivation = new DirectKeyDerivation(),
    ) {
    }

    /** Errors are logged and swallowed: the session keeps the previous key. */
    public function updateSecret(string $secret): void
    {
        if ($secret === $this->appliedSecret) {
            return;
        }
        try {
            $parts = explode('.', $secret);
            if (count($parts) !== 3) {
                throw new RuntimeException('malformed secret');
            }
            // 1. open the password: RSA → bytes of the Base64 text → password
            $message = $this->security->rsa->decrypt(gmp_init($parts[0], 36));
            $password = base64_decode(gmp_export($message), true);   // gmp_export: integer → big-endian bytes
            if ($password === false) {
                throw new RuntimeException('secret password is not Base64');
            }
            // 2. salt and IV
            $salt = self::b64UrlDecode($parts[1]);
            $iv = self::b64UrlDecode($parts[2]);
            // 3. the same derivation as the client (on the server, in a separate process)
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
            throw new RuntimeException('Failed to encrypt: ' . openssl_error_string());
        }

        return $cipherText . $tag;   // the browser's WebCrypto also appends the tag at the end
    }

    public function decipher(string $data): string
    {
        [$key, $iv] = $this->material();
        if (strlen($data) < self::TAG_LENGTH) {
            throw new RuntimeException('Ciphertext too short');
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
            throw new RuntimeException('Failed to decrypt (wrong key or tampered data)');
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
            throw new RuntimeException('Ciphertext is not Base64');
        }

        return $this->decipher($data);
    }

    /** @return array{string, string} */
    private function material(): array
    {
        if ($this->key === null || $this->iv === null) {
            throw new RuntimeException('Channel has no key: the handshake (secret) has not happened yet');
        }

        return [$this->key, $this->iv];
    }

    private static function b64UrlDecode(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);   // padding is optional
        if ($bytes === false) {
            throw new RuntimeException('Invalid URL-safe Base64');
        }

        return $bytes;
    }
}
