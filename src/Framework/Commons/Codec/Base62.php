<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Codec;

use InvalidArgumentException;

/**
 * Base62 com o alfabeto do GMP (0-9 A-Z a-z).
 *
 * Por que não `gmp_strval(gmp_import($bytes), 62)`? Mesmo alfabeto, mas o GMP descarta os
 * zeros à esquerda; este algoritmo preserva um dígito "0" para cada byte 0x00 inicial
 * (menos o último). Ids e assinaturas são trocados com os clientes, então a saída tem de ficar
 * estável byte a byte: o formato é parte do protocolo.
 */
final class Base62
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private function __construct()
    {
    }

    public static function encode(string $bytes): string
    {
        $digits = self::convert(array_values(unpack('C*', $bytes) ?: []), 256, 62, strlen($bytes));

        return implode('', array_map(static fn (int $d): string => self::ALPHABET[$d], $digits));
    }

    public static function decode(string $text): string
    {
        if (preg_match('/^[0-9A-Za-z]*$/', $text) !== 1) {
            throw new InvalidArgumentException('Input is not encoded correctly');
        }
        $digits = array_map(static fn (string $c): int => (int) strpos(self::ALPHABET, $c), str_split($text) ?: []);
        $bytes = self::convert($digits, 62, 256, count($digits));

        return pack('C*', ...$bytes);
    }

    /**
     * Mudança de base por divisões sucessivas.
     *
     * @param list<int> $source dígitos na base de origem, mais significativo primeiro
     * @return list<int>
     */
    private static function convert(array $source, int $sourceBase, int $targetBase, int $length): array
    {
        $original = $source;
        $out = [];
        while ($source !== []) {
            $quotient = [];
            $remainder = 0;
            foreach ($source as $digit) {
                $accumulator = $digit + $remainder * $sourceBase;
                $q = intdiv($accumulator, $targetBase);
                $remainder = $accumulator % $targetBase;
                if ($quotient !== [] || $q > 0) {
                    $quotient[] = $q;
                }
            }
            $out[] = $remainder;
            $source = $quotient;
        }
        // um zero para cada zero inicial da mensagem (menos o último)
        for ($i = 0; $i < $length - 1 && $original[$i] === 0; $i++) {
            $out[] = 0;
        }

        return array_reverse($out);
    }
}
