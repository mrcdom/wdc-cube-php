<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Typed reading of an intent: the only place that knows the NAMES of a place's parameters and
 * attributes.
 *
 * Each place that reads or publishes something has its own keys class, which extends the keys of
 * the place above it on the path - so a deeper place can READ what an ancestor publishes. Each
 * file declares a two-letter prefix, unique in the application, and its names in a local table
 * of constants, all starting with the prefix:
 *
 *     private const string PREFIX = 'pr';
 *     private const string P_ID = self::PREFIX . '-id';           // parameter: goes into the address
 *     private const string A_ROW = self::PREFIX . '-row';         // attribute: does not
 *
 * Uniqueness matters because a navigation passes the SAME intent through every `applyParameters`,
 * from root to leaf: two owners of the same name would overwrite each other's value along the
 * way, silently. The `KeysNamesTest` test enforces the rule by scanning the `*Keys.php` files.
 *
 * Values are exposed as properties with hooks (`get`/`set`), which validate on read - the
 * address is outside input - and omit the default value on write.
 *
 * @template A of CubeApplication
 * @phpstan-consistent-constructor
 */
abstract class CubeKeys
{
    public readonly CubeIntent $intent;

    /**
     * @param A               $app
     * @param CubeIntent|null $intent the intent of a navigation in progress; null = built from
     *                                the current STATE (each live presenter publishes what it shows)
     */
    public function __construct(protected readonly CubeApplication $app, ?CubeIntent $intent = null)
    {
        if ($intent === null) {
            $intent = $app->newIntent();
            $intent->setPlace($this->place());
        }
        $this->intent = $intent;
    }

    /**
     * Keys for a trip that starts from the current state: `ProductKeys::of($app)` and then `->go()`.
     *
     * @param A $app
     */
    public static function of(CubeApplication $app): static
    {
        return new static($app);
    }

    /** The place of these keys (the destination of `go()`). */
    abstract public function place(): CubePlace;

    /** Navigates to the place of these keys, with the intent they built. */
    abstract public function go(): bool;

    /** A non-negative integer (ids start at 0), or null when the address does not carry a usable one. */
    protected function idParameter(string $name): ?int
    {
        $value = $this->intent->getParameterAsInt($name);

        return $value !== null && $value >= 0 ? $value : null;
    }
}
