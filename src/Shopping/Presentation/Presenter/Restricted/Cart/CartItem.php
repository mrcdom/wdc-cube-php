<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Cart;

use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductInfo;

final class CartItem
{
    public int $id = -1;

    public string $image = '';

    public string $name = '';

    public float $price = 0.0;

    public int $quantity = 0;

    public static function create(ProductInfo $product, int $quantity): self
    {
        $item = new self();
        $item->id = $product->id;
        $item->name = $product->name;
        $item->image = $product->image;
        $item->price = $product->price;
        $item->quantity = $quantity;

        return $item;
    }
}
