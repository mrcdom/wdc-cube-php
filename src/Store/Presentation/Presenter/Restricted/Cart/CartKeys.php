<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Cart;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Store\Presentation\Presenter\Restricted\Home\HomeKeys;
use Wdc\Store\Presentation\Place;

/** Cart keys: `cart`. */
final class CartKeys extends HomeKeys
{
    public function place(): CubePlace
    {
        return Place::CART;
    }
}
