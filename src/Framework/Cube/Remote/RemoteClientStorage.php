<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Throwable;
use Wdc\Framework\Commons\Storage\ClientStorage;

/**
 * Server-side mirror of the browser storage (sessionStorage/localStorage).
 *
 * When the session is created the client sends everything it has (encrypted); afterwards, every change
 * made by the app becomes a "delta" sent, encrypted, in the next response. Three scopes on the wire:
 * `session`, `persistent` and `persistent-secure` (the last two are the same map on the server).
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

    /** @var array<string, array<string, ?string>> scope → key → value (null = remove) */
    private array $deltas = [];

    public function __construct(private readonly RemoteDataSecurity $security)
    {
    }

    /** Initial load coming from the client. Values that fail to decrypt are ignored. */
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
     * Pending deltas, already encrypted for the wire, and clears them. null = nothing to send.
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

    /** `secure()` of this view records deltas in the `persistent-secure` scope. */
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
                    // tampered value or from another session: ignore
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
