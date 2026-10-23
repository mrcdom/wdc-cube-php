<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Codec;

use InvalidArgumentException;

/**
 * Base62 with the GMP alphabet (0-9 A-Z a-z).
 *
 * Why not `gmp_strval(gmp_import($bytes), 62)`? Same alphabet, but GMP drops leading zeros;
 * this algorithm keeps one "0" digit for each leading 0x00 byte (except the last one). Ids and
 * signatures are exchanged with clients, so the output must stay stable byte for byte: the
 * format is part of the protocol.
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
     * Base conversion by repeated division.
     *
     * @param list<int> $source digits in the source base, most significant first
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
        // one zero for each leading zero of the message (except the last one)
        for ($i = 0; $i < $length - 1 && $original[$i] === 0; $i++) {
            $out[] = 0;
        }

        return array_reverse($out);
    }
}
