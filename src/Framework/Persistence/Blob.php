<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

/**
 * Marca um parâmetro como binário. Em PHP bytes são só `string`; sem a marca o valor
 * iria ao banco como texto, e não como BYTEA.
 */
final readonly class Blob
{
    public function __construct(public string $bytes)
    {
    }
}
