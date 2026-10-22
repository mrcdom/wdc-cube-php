<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use Closure;

/**
 * O contexto de segurança "da chamada corrente".
 *
 * Um estático simples basta aqui: o servidor roda num só processo e cada sessão é atendida
 * por vez. `runAs()` põe o contexto, executa e restaura — e como as chamadas de repositório são
 * síncronas (nenhuma Fiber cede a vez no meio delas), outra sessão nunca o enxerga.
 */
final class CurrentSecurityContext
{
    private static ?SecurityContext $current = null;

    private function __construct()
    {
    }

    public static function get(): ?SecurityContext
    {
        return self::$current;
    }

    public static function set(?SecurityContext $context): void
    {
        self::$current = $context;
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public static function runAs(?SecurityContext $context, Closure $work): mixed
    {
        $previous = self::$current;
        if ($context !== null) {
            self::$current = $context;
        }
        try {
            return $work();
        } finally {
            self::$current = $previous;
        }
    }
}
