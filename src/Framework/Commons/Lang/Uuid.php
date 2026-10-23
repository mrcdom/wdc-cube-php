<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Lang;

final class Uuid
{
    private function __construct()
    {
    }

    /** Version 4 (random) UUID. */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);   // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);   // RFC 4122 variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
