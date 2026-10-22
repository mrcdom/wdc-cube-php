<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Lang;

final class Uuid
{
    private function __construct()
    {
    }

    /** UUID versão 4 (aleatório), como `UUID.randomUUID()`. */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);   // versão 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);   // variante RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
