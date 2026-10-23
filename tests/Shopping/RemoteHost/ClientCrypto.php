<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\RemoteHost;

use PHPUnit\Framework\Assert;
use Wdc\Framework\Cube\Remote\RemoteDataSecurity;

/**
 * The client side of the channel encryption, step by step like the shell's DataSecurity.ts:
 * random password → PBKDF2 → AES-GCM; the password is encrypted with the key from the `app_skey` cookie.
 */
final readonly class ClientCrypto
{
    private function __construct(
        public string $secret,
        private string $key,
        private string $iv,
    ) {
    }

    /** @param string $webKey the value of the `app_skey` cookie ("e:n" in base 36) */
    public static function handshake(string $webKey): self
    {
        [$e, $n] = explode(':', $webKey, 2);
        $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $password, $salt, RemoteDataSecurity::PBKDF2_ITERATIONS, 32, true);
        $encrypted = gmp_powm(gmp_import(base64_encode($password)), gmp_init($e, 36), gmp_init($n, 36));
        $b64url = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

        return new self(gmp_strval($encrypted, 36) . '.' . $b64url($salt) . '.' . $b64url($iv), $key, $iv);
    }

    public function cipher(string $text): string
    {
        $out = openssl_encrypt($text, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $this->iv, $tag, '', 16);

        return base64_encode($out . $tag);
    }

    public function decipher(string $b64): string
    {
        $data = (string) base64_decode($b64, true);
        $plain = openssl_decrypt(substr($data, 0, -16), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $this->iv, substr($data, -16));
        Assert::assertIsString($plain, 'the client could not decrypt what the server sent');

        return $plain;
    }
}
