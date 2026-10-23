<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Storage;

/**
 * Client-side key/value storage (the browser's sessionStorage / localStorage, in the case of
 * the remote shell).
 */
interface ClientStorage
{
    /** Variant backed by secure (encrypted) storage, when the platform offers one. */
    public function secure(): self;

    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function remove(string $key): void;

    /** @return array<string, string> */
    public function all(): array;
}
