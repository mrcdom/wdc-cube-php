<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Security;

use GMP;

/**
 * RSA "de livro" (sem padding). Usada só no handshake do canal:
 * o cliente cifra a senha da sessão com `m^e mod n` e o servidor a abre com `c^d mod n`.
 *
 * Não use para mais nada — RSA sem padding não é seguro em geral; aqui o conteúdo cifrado
 * é aleatório e de uso único, e o formato é o contrato com o shell React.
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

    /** Gera um par de ~$bits bits (dois primos de $bits/2). */
    public static function generate(int $bits = 512): self
    {
        $e = gmp_init(self::PUBLIC_EXPONENT);
        do {
            $p = self::randomPrime(intdiv($bits, 2));
            $q = self::randomPrime(intdiv($bits, 2));
            $phi = ($p - 1) * ($q - 1);   // GMP sobrecarrega os operadores aritméticos
        } while ($p == $q || gmp_cmp(gmp_gcd($e, $phi), 1) !== 0);

        $d = gmp_invert($e, $phi);
        assert($d instanceof GMP);

        return new self($e, $d, $p * $q);
    }

    /** "e:n" em base 36 (pública) e "d" em base 36 (privada) — o formato do protocolo. */
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
        $candidate = gmp_or($candidate, gmp_pow(2, $bits - 1));   // garante o tamanho

        return gmp_nextprime($candidate);
    }
}
