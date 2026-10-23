<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Security;

use GMP;

/**
 * "Textbook" RSA (no padding). Used only in the channel handshake:
 * the client encrypts the session password with `m^e mod n` and the server opens it with `c^d mod n`.
 *
 * Do not use it for anything else — unpadded RSA is not secure in general; here the encrypted
 * content is random and single-use, and the format is the contract with the React shell.
 */
final readonly class Rsa
{
    public const int PUBLIC_EXPONENT = 65537;

    public function __construct(
        public GMP $publicExponent,
        public GMP $privateKey,
        public GMP $modulus,
    ) {
    }

    /** Generates a ~$bits-bit key pair (two $bits/2-bit primes). */
    public static function generate(int $bits = 512): self
    {
        $e = gmp_init(self::PUBLIC_EXPONENT);
        do {
            $p = self::randomPrime(intdiv($bits, 2));
            $q = self::randomPrime(intdiv($bits, 2));
            $phi = ($p - 1) * ($q - 1);   // GMP overloads the arithmetic operators
        } while ($p == $q || gmp_cmp(gmp_gcd($e, $phi), 1) !== 0);

        $d = gmp_invert($e, $phi);
        assert($d instanceof GMP);

        return new self($e, $d, $p * $q);
    }

    /** "e:n" in base 36 (public) and "d" in base 36 (private) — the protocol format. */
    public static function fromBase36(string $publicKey, string $privateKey): self
    {
        [$e, $n] = explode(':', $publicKey, 2) + [1 => '0'];

        return new self(gmp_init($e, 36), gmp_init($privateKey, 36), gmp_init($n, 36));
    }

    public function publicKeyBase36(): string
    {
        return gmp_strval($this->publicExponent, 36) . ':' . gmp_strval($this->modulus, 36);
    }

    public function privateKeyBase36(): string
    {
        return gmp_strval($this->privateKey, 36);
    }

    public function encrypt(GMP $message): GMP
    {
        return gmp_powm($message, $this->publicExponent, $this->modulus);
    }

    public function decrypt(GMP $encrypted): GMP
    {
        return gmp_powm($encrypted, $this->privateKey, $this->modulus);
    }

    private static function randomPrime(int $bits): GMP
    {
        $candidate = gmp_import(random_bytes(max(1, intdiv($bits, 8))));
        $candidate = gmp_or($candidate, gmp_pow(2, $bits - 1));   // guarantees the bit length

        return gmp_nextprime($candidate);
    }
}
