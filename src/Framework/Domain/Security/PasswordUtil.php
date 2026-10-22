<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

/**
 * Hash de senha: os 16 bytes do MD5 da senha lidos como inteiro e escritos em base 36.
 *
 * O formato é fixo (há hashes já gravados): os bytes são lidos como número COM sinal (complemento de dois),
 * por isso algumas senhas geram hash negativo (ex.: "beotrano" → "-17msdx5ah76k0tdyvaoieqemg").
 */
final class PasswordUtil
{
    private function __construct()
    {
    }

    public static function hashPassword(string $plain): string
    {
        $digest = md5($plain, true);           // true = 16 bytes binários, não hex
        $n = gmp_import($digest);              // lê como inteiro sem sinal, big-endian

        if ((ord($digest[0]) & 0x80) !== 0) {  // bit mais alto ligado ⇒ negativo
            $n = gmp_sub($n, gmp_pow(2, 128));
        }

        return gmp_strval($n, 36);
    }

    /** hex(HMAC-SHA256(key, data)) em minúsculas — o digest do login por desafio. */
    public static function computeHmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key);
    }
}
