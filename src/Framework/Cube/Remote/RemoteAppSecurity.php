<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use OpenSSLAsymmetricKey;
use RuntimeException;
use Wdc\Framework\Commons\Codec\Base62;
use Wdc\Framework\Commons\Security\Rsa;

/**
 * The remote server keys (once per process):
 *
 * - **handshake**: "textbook" RSA in base 36 (`e:n` public, `d` private). The public key goes to
 *   the client in the `app_skey` cookie; the client uses it to encrypt the session password.
 * - **signing**: regular RSA (DER in URL-safe Base64: X.509 public, PKCS#8 private), used to
 *   sign the `appId` and the URLs (`sign=`) with SHA256withRSA.
 */
final class RemoteAppSecurity
{
    private OpenSSLAsymmetricKey $signPrivateKey;

    private OpenSSLAsymmetricKey $signPublicKey;

    public function __construct(
        public readonly Rsa $rsa,
        string $signPublicKeyB64Url,
        string $signPrivateKeyB64Url,
    ) {
        $this->signPrivateKey = self::loadKey(
            openssl_pkey_get_private(self::pem('PRIVATE KEY', $signPrivateKeyB64Url)),
            'signing private key',
        );
        $this->signPublicKey = self::loadKey(
            openssl_pkey_get_public(self::pem('PUBLIC KEY', $signPublicKeyB64Url)),
            'signing public key',
        );
    }

    /**
     * Generates a set of development keys (512 bits).
     *
     * @return array{rsaPublicKey: string, rsaPrivateKey: string, signPublicKey: string, signPrivateKey: string}
     */
    public static function generateDevKeys(): array
    {
        $rsa = Rsa::generate(512);
        $sign = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($sign === false || !openssl_pkey_export($sign, $privatePem)) {
            throw new RuntimeException('Failed to generate signing keys: ' . openssl_error_string());
        }
        $details = openssl_pkey_get_details($sign);
        $publicPem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

        return [
            'rsaPublicKey' => $rsa->publicKeyBase36(),
            'rsaPrivateKey' => $rsa->privateKeyBase36(),
            'signPublicKey' => self::pemToB64Url($publicPem),
            'signPrivateKey' => self::pemToB64Url(is_string($privatePem) ? $privatePem : ''),
        ];
    }

    /** What goes into the `app_skey` cookie: "exponent:modulus" in base 36. */
    public function webKey(): string
    {
        return $this->rsa->publicKeyBase36();
    }

    /** SHA256withRSA (PKCS#1 v1.5) — deterministic: the same key and content always yield the same signature. */
    public function sign(string $content): string
    {
        if (!openssl_sign($content, $signature, $this->signPrivateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign: ' . openssl_error_string());
        }

        return is_string($signature) ? $signature : '';
    }

    /** MD5 of the signature: 16 bytes, to fit in a URL parameter. */
    public function signAsHash(string $content): string
    {
        return md5($this->sign($content), true);
    }

    /**
     * Identity of a session: `base62(32 random bytes) . "." . base62(md5(their signature))`.
     * Only the server generates and checks it; the client just stores and returns it.
     */
    public function newAppId(): string
    {
        $random = Base62::encode(random_bytes(32));

        return $random . '.' . Base62::encode($this->signAsHash($random));
    }

    public function isValidAppId(string $appId): bool
    {
        $parts = explode('.', $appId);

        return count($parts) === 2 && $parts[0] !== ''
            && hash_equals(Base62::encode($this->signAsHash($parts[0])), $parts[1]);
    }

    public function isSignatureValid(string $content, string $signature): bool
    {
        return openssl_verify($content, $signature, $this->signPublicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function pem(string $label, string $derB64Url): string
    {
        $b64 = strtr($derB64Url, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);   // PEM requires the padding that URL-safe strips

        return "-----BEGIN {$label}-----\n" . chunk_split($b64, 64, "\n") . "-----END {$label}-----\n";
    }

    private static function pemToB64Url(string $pem): string
    {
        $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '';

        return rtrim(strtr($b64, '+/', '-_'), '=');
    }

    private static function loadKey(OpenSSLAsymmetricKey|false $key, string $what): OpenSSLAsymmetricKey
    {
        if ($key === false) {
            throw new RuntimeException("Invalid {$what}: " . openssl_error_string());
        }

        return $key;
    }
}
