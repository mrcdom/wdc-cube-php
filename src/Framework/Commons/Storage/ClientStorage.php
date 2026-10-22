<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Storage;

/**
 * Armazenamento chave/valor do lado do cliente (sessionStorage / localStorage do browser,
 * no caso do shell remoto).
 */
interface ClientStorage
{
    /** Variante com armazenamento seguro (cifrado), quando a plataforma oferece. */
    public function secure(): self;

    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function remove(string $key): void;

    /** @return array<string, string> */
    public function all(): array;
}
