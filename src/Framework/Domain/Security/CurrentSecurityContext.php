<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use Closure;
use Wdc\Framework\Commons\Concurrent\FiberValue;

/**
 * The security context "of the current call".
 *
 * It is stored **per Fiber**: database queries suspend the Fiber that issued them, and in the meantime
 * other sessions run — each must see only its own context. `runAs()` sets the
 * context, runs the work and restores it.
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
