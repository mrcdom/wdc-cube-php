<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Closure;
use Wdc\Framework\Commons\Storage\ClientStorage;

/**
 * A view over one scope of the RemoteClientStorage.
 *
 * Receives the data map **by reference** (`&$data`): several views (persistent and
 * persistent-secure) read and write the same map, which lives in the RemoteClientStorage.
 *
 * @internal
 */
final class ScopedClientStorage implements ClientStorage
{
    /** @var array<string, string> */
    private array $data;

    /**
     * @param array<string, string>        $data
     * @param Closure(string, ?string): void $recordDelta
     */
    public function __construct(
        array &$data,
        private readonly Closure $recordDelta,
        private readonly ?ClientStorage $secureVariant = null,
    ) {
        $this->data = &$data;   // the property becomes an alias of the owner's array
    }

    public function secure(): ClientStorage
    {
        return $this->secureVariant ?? $this;
    }

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->data[$key] = $value;
        ($this->recordDelta)($key, $value);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        ($this->recordDelta)($key, null);
    }

    public function all(): array
    {
        return $this->data;
    }
}
