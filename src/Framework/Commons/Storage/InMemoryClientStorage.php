<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Storage;

/** In-memory ClientStorage — for tests and for when there is no client. */
final class InMemoryClientStorage implements ClientStorage
{
    /** @var array<string, string> */
    private array $values = [];

    private ?self $secure = null;

    public function secure(): ClientStorage
    {
        return $this->secure ??= new self();
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function all(): array
    {
        return $this->values;
    }
}
