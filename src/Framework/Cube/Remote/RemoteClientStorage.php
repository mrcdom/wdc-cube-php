<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Throwable;
use Wdc\Framework\Commons\Storage\ClientStorage;

/**
 * Espelho no servidor do armazenamento do browser (sessionStorage/localStorage).
 *
 * Na criação da sessão o cliente manda tudo o que tem (cifrado); depois, cada alteração feita
 * pela app vira um "delta" que sai na próxima resposta, cifrado. Três escopos no fio:
 * `session`, `persistent` e `persistent-secure` (os dois últimos são o mesmo mapa no servidor).
 */
final class RemoteClientStorage
{
    public const string SCOPE_SESSION = 'session';
    public const string SCOPE_PERSISTENT = 'persistent';
    public const string SCOPE_PERSISTENT_SECURE = 'persistent-secure';

    /** @var array<string, string> */
    private array $sessionData = [];

    /** @var array<string, string> */
    private array $persistentData = [];

    /** @var array<string, array<string, ?string>> escopo → chave → valor (null = remover) */
    private array $deltas = [];

    public function __construct(private readonly RemoteDataSecurity $security)
    {
    }

    /** Carga inicial vinda do cliente. Valores que não decifram são ignorados. */
    public function loadFromBootstrap(mixed $storage): void
    {
        if (!is_array($storage)) {
            return;
        }
        $this->loadScope($storage[self::SCOPE_SESSION] ?? null, $this->sessionData);
        $this->loadScope($storage[self::SCOPE_PERSISTENT] ?? null, $this->persistentData);
        $this->loadScope($storage[self::SCOPE_PERSISTENT_SECURE] ?? null, $this->persistentData);
    }

    /**
     * Deltas pendentes já cifrados para o fio, e os limpa. null = nada a enviar.
     *
     * @return array<string, array<string, ?string>>|null
     */
    public function drainDeltas(): ?array
    {
        if ($this->deltas === []) {
            return null;
        }
        $out = [];
        foreach ($this->deltas as $scope => $entries) {
            foreach ($entries as $key => $value) {
                $out[$scope][$key] = $value === null ? null : $this->security->b64Cipher($value);
            }
        }
        $this->deltas = [];

        return $out;
    }

    public function session(): ClientStorage
    {
        return new ScopedClientStorage($this->sessionData, $this->recorder(self::SCOPE_SESSION));
    }

    /** `secure()` desta visão grava os deltas no escopo `persistent-secure`. */
    public function persistent(): ClientStorage
    {
        $secure = new ScopedClientStorage($this->persistentData, $this->recorder(self::SCOPE_PERSISTENT_SECURE));

        return new ScopedClientStorage($this->persistentData, $this->recorder(self::SCOPE_PERSISTENT), $secure);
    }

    /**
     * @param array<string, string> $target
     */
    private function loadScope(mixed $scope, array &$target): void
    {
        if (!is_array($scope)) {
            return;
        }
        foreach ($scope as $key => $ciphered) {
            if (is_string($ciphered) && trim($ciphered) !== '') {
                try {
                    $target[(string) $key] = $this->security->b64Decipher($ciphered);
                } catch (Throwable) {
                    // valor adulterado ou de outra sessão: ignora
                }
            }
        }
    }

    /** @return \Closure(string, ?string): void */
    private function recorder(string $scope): \Closure
    {
        return function (string $key, ?string $value) use ($scope): void {
            $this->deltas[$scope][$key] = $value;
        };
    }
}
