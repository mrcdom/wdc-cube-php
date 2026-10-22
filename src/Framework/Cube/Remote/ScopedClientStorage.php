<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Closure;
use Wdc\Framework\Commons\Storage\ClientStorage;

/**
 * Uma visão de um escopo do RemoteClientStorage.
 *
 * Recebe o mapa de dados **por referência** (`&$data`): várias visões (persistent e
 * persistent-secure) leem e gravam o mesmo mapa, que mora no RemoteClientStorage.
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
        $this->data = &$data;   // a propriedade passa a ser um apelido do array do dono
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
