<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use Closure;
use Wdc\Framework\Commons\Concurrent\FiberValue;

/**
 * O contexto de segurança "da chamada corrente".
 *
 * É guardado **por Fiber**: as consultas ao banco suspendem a Fiber que as fez, e nesse meio-tempo
 * outras sessões rodam — cada uma precisa enxergar só o próprio contexto. `runAs()` põe o
 * contexto, executa e restaura.
 */
final class CurrentSecurityContext
{
    /** @var FiberValue<SecurityContext>|null */
    private static ?FiberValue $current = null;

    private function __construct()
    {
    }

    public static function get(): ?SecurityContext
    {
        return self::local()->get();
    }

    public static function set(?SecurityContext $context): void
    {
        self::local()->set($context);
    }

    /** @return FiberValue<SecurityContext> */
    private static function local(): FiberValue
    {
        return self::$current ??= new FiberValue(SecurityContext::class);
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public static function runAs(?SecurityContext $context, Closure $work): mixed
    {
        $previous = self::get();
        if ($context !== null) {
            self::set($context);
        }
        try {
            return $work();
        } finally {
            self::set($previous);
        }
    }
}
