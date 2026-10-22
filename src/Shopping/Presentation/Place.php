<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation;

use LogicException;
use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePlace;
use Wdc\Framework\Cube\CubePresenter;
use Wdc\Shopping\Presentation\Presenter\Open\Login\LoginPresenter;
use Wdc\Shopping\Presentation\Presenter\Open\OpenPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomePresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\RestrictedPresenter;
use Wdc\Shopping\Presentation\Presenter\RootPresenter;

/**
 * A árvore de places do Shopping:
 *
 *     ROOT ─┬─ OPEN ──────── LOGIN
 *           └─ RESTRICTED ── HOME ─┬─ CART
 *                                  ├─ PRODUCT
 *                                  └─ RECEIPT
 *
 * O id (valor do enum) cresce com a profundidade — o motor libera presenters da folha para a raiz.
 * Enum PHP não tem construtor nem estado, então a rota de cada place é o método `go()`.
 */
enum Place: int implements CubePlace
{
    // nível 0
    case ROOT = 0;
    // nível 1
    case OPEN = 1;
    case RESTRICTED = 2;
    // nível 2
    case LOGIN = 3;
    case HOME = 4;
    // nível 3
    case CART = 5;
    case PRODUCT = 6;
    case RECEIPT = 7;

    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $place) {
            if ($place->name() === $name) {
                return $place;
            }
        }

        return null;
    }

    public function id(): int
    {
        return $this->value;
    }

    /** O nome que aparece na URL. */
    public function name(): string
    {
        return match ($this) {
            self::ROOT => 'public',
            self::OPEN => 'open',
            self::RESTRICTED => 'restricted',
            self::LOGIN => 'login',
            self::HOME => 'home',
            self::CART => 'cart',
            self::PRODUCT => 'product',
            self::RECEIPT => 'receipt',
        };
    }

    public function createPresenter(CubeApplication $app): CubePresenter
    {
        if (!$app instanceof ShoppingApplication) {
            throw new LogicException('Place do Shopping exige ShoppingApplication');
        }

        return match ($this) {
            self::ROOT => new RootPresenter($app),
            self::OPEN => new OpenPresenter($app),
            self::RESTRICTED => new RestrictedPresenter($app),
            self::LOGIN => new LoginPresenter($app),
            self::HOME => new HomePresenter($app),
            self::CART => new CartPresenter($app),
            self::PRODUCT => new ProductPresenter($app),
            self::RECEIPT => new ReceiptPresenter($app),
        };
    }

    /** A rota que leva a este place. */
    public function go(ShoppingApplication $app, CubeIntent $intent): bool
    {
        return match ($this) {
            self::ROOT => Routes::root($app, $intent),
            self::OPEN => Routes::open($app, $intent),
            self::RESTRICTED => Routes::restricted($app, $intent),
            self::LOGIN => Routes::login($app, $intent),
            self::HOME => Routes::home($app, $intent),
            self::CART => Routes::cart($app, $intent),
            self::PRODUCT => Routes::product($app, $intent),
            self::RECEIPT => Routes::receipt($app, $intent),
        };
    }
}
