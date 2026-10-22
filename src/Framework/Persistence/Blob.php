<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

/**
 * Marca um parâmetro como binário. Em PHP bytes são só `string`; sem a marca o PDO
 * gravaria o valor como TEXT.
 */
final readonly class Blob
{
    public function __construct(public string $bytes)
    {
    }
}
