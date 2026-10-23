<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation;

use Wdc\Framework\Cube\CubeIntent;

/**
 * Each route is the chain of places from the root to the destination. Omitted intent = current state
 * (`$app->newIntent()`).
 */
final class Routes
{
    private function __construct()
    {
    }

    // -- level 0 --

    public static function root(ShoppingApplication $app, ?CubeIntent $intent = null): bool
    {
        return $app->subject() === null ? self::login($app, $intent) : self::home($app, $intent);
    }

    // -- level 1 --

    public static function open(ShoppingApplication $app, ?CubeIntent $intent = null): bool
    {
        return $app->navigate()->step(Place::ROOT)->step(Place::OPEN)
            ->execute($intent ?? $app->newIntent());
    }

    public static function restricted(ShoppingApplication $app, ?CubeIntent $intent = null): bool
    {
        return $app->navigate()->step(Place::ROOT)->step(Place::RESTRICTED)
            ->execute($intent ?? $app->newIntent());
    }

    // -- level 2 --

    public static function login(ShoppingApplication $app, ?CubeIntent $intent = null): bool
    {
        return $app->navigate()->step(Place::ROOT)->step(Place::OPEN)->step(Place::LOGIN)
            ->execute($intent ?? $app->newIntent());
    }

    public static function home(ShoppingApplication $app, ?CubeIntent $intent = null): bool
    {
        return $app->navigate()->step(Place::ROOT)->step(Place::RESTRICTED)->step(Place::HOME)
            ->execute($intent ?? $app->newIntent());
    }

    // -- level 3 --

    public static function cart(ShoppingApplication $app, ?CubeIntent $intent = null): bool
    {
        return $app->navigate()->step(Place::ROOT)->step(Place::RESTRICTED)->step(Place::HOME)->step(Place::CART)
            ->execute($intent ?? $app->newIntent());
    }

    public static function product(ShoppingApplication $app, CubeIntent $intent): bool
    {
        return $app->navigate()->step(Place::ROOT)->step(Place::RESTRICTED)->step(Place::HOME)->step(Place::PRODUCT)
            ->execute($intent);
    }

    public static function receipt(ShoppingApplication $app, CubeIntent $intent): bool
    {
        return $app->navigate()->step(Place::ROOT)->step(Place::RESTRICTED)->step(Place::HOME)->step(Place::RECEIPT)
            ->execute($intent);
    }
}
