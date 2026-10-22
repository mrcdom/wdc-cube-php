<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use OpenSSLAsymmetricKey;
use RuntimeException;
use Wdc\Framework\Commons\Codec\Base62;
use Wdc\Framework\Commons\Security\Rsa;

/**
 * As chaves do servidor remoto (uma vez por processo):
 *
 * - **handshake**: RSA "de livro" em base 36 (`e:n` público, `d` privado). A pública vai ao
 *   cliente no cookie `app_skey`; com ela o cliente cifra a senha da sessão.
 * - **assinatura**: RSA comum (DER em Base64 URL-safe: X.509 pública, PKCS#8 privada), para
 *   assinar o `appId` e as URLs (`sign=`) com SHA256withRSA.
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
            'privada de assinatura',
        );
        $this->signPublicKey = self::loadKey(
            openssl_pkey_get_public(self::pem('PUBLIC KEY', $signPublicKeyB64Url)),
            'pública de assinatura',
        );
    }

    /**
     * Gera um jogo de chaves de desenvolvimento (512 bits).
     *
     * @return array{rsaPublicKey: string, rsaPrivateKey: string, signPublicKey: string, signPrivateKey: string}
     */
    public static function generateDevKeys(): array
    {
        $rsa = Rsa::generate(512);
        $sign = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($sign === false || !openssl_pkey_export($sign, $privatePem)) {
            throw new RuntimeException('Falha ao gerar chaves de assinatura: ' . openssl_error_string());
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

    /** O que vai no cookie `app_skey`: "expoente:módulo" em base 36. */
    public function webKey(): string
    {
        return $this->rsa->publicKeyBase36();
    }

    /** SHA256withRSA (PKCS#1 v1.5) — determinístico: a mesma chave e o mesmo conteúdo dão sempre a mesma assinatura. */
    public function sign(string $content): string
    {
        if (!openssl_sign($content, $signature, $this->signPrivateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha ao assinar: ' . openssl_error_string());
        }

        return is_string($signature) ? $signature : '';
    }

    /** MD5 da assinatura: 16 bytes, para caber num parâmetro de URL. */
    public function signAsHash(string $content): string
    {
        return md5($this->sign($content), true);
    }

    /**
     * Identidade de uma sessão: `base62(32 bytes aleatórios) . "." . base62(md5(assinatura deles))`.
     * Só o servidor gera e confere; o cliente apenas guarda e devolve.
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
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);   // o PEM exige o padding que o URL-safe tira

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
            throw new RuntimeException("Chave {$what} inválida: " . openssl_error_string());
        }

        return $key;
    }
}
