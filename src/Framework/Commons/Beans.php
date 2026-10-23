<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons;

use LogicException;

/**
 * The process-wide shared services (repositories, transactions, authentication), looked up by
 * interface: `Beans::of(ProductRepository::class)`.
 *
 * They are filled in once, at server startup (or in a test's `setUp`), and only read afterwards.
 * That is why they can serve every session at the same time without trouble from Fibers: nothing
 * that changes per request lives here.
 *
 * The key is the interface, and PHPStan knows the return type: `of(ProductRepository::class)` is a
 * `ProductRepository`. (A PHP interface cannot have a static property, so the registry lives in a
 * separate class.)
 */
final class Beans
{
    /** @var array<class-string, object> */
    private static array $beans = [];

    private function __construct()
    {
    }

    /**
     * Registers (or, with `null`, removes) the implementation of an interface.
     *
     * @template T of object
     * @param class-string<T> $type
     * @param T|null          $bean
     */
    public static function set(string $type, ?object $bean): void
    {
        if ($bean === null) {
            unset(self::$beans[$type]);

            return;
        }
        if (!$bean instanceof $type) {
            throw new LogicException(get_class($bean) . " does not implement {$type}");
        }
        self::$beans[$type] = $bean;
    }

    /**
     * The registered implementation; a missing registration is a wiring error.
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public static function of(string $type): object
    {
        return self::find($type) ?? throw new LogicException("No implementation registered for {$type}");
    }

    /**
     * The registered implementation, or `null` for an optional service (e.g. authentication turned off).
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public static function find(string $type): ?object
    {
        $bean = self::$beans[$type] ?? null;

        return $bean instanceof $type ? $bean : null;
    }

    /** Empties the registry (tests). */
    public static function clear(): void
    {
        self::$beans = [];
    }
}
