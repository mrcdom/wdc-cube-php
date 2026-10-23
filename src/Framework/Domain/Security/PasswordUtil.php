<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

/**
 * Password hash: the 16 bytes of the password's MD5 read as an integer and written in base 36.
 *
 * The format is fixed (hashes are already stored): the bytes are read as a SIGNED number (two's complement),
 * so some passwords produce a negative hash (e.g. "shopping" → "-4k4g46ozrhcc0ed8pgz69wgxx").
 */
final class PasswordUtil
{
    private function __construct()
    {
    }

    public static function hashPassword(string $plain): string
    {
        $digest = md5($plain, true);           // true = 16 raw bytes, not hex
        $n = gmp_import($digest);              // read as an unsigned big-endian integer

        if ((ord($digest[0]) & 0x80) !== 0) {  // high bit set ⇒ negative
            $n = gmp_sub($n, gmp_pow(2, 128));
        }

        return gmp_strval($n, 36);
    }

    /** Lowercase hex(HMAC-SHA256(key, data)) — the challenge-login digest. */
    public static function computeHmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key);
    }
}
