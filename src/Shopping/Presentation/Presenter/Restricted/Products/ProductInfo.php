<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Products;

use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;

/** A product as the screen shows it. Presentation structs have public fields. */
final class ProductInfo
{
    public int $id = -1;

    public string $image = '';

    public string $name = 'unknown';

    /** HTML */
    public string $description = 'unknown';

    public float $price = 0.0;

    public static function projection(): Product
    {
        return new Product()->withId(PV::INT)->withName(PV::STR)->withPrice(PV::FLOAT)->withDescription(PV::STR);
    }

    public static function create(?Product $product): ?self
    {
        if ($product === null) {
            return null;
        }
        $info = new self();
        $info->id = $product->id() ?? -1;
        $info->name = $product->name() ?? 'unknown';
        $info->price = $product->price() ?? 0.0;
        $info->description = $product->description() ?? 'unknown';
        $info->image = "image/product/{$info->id}.png";

        return $info;
    }
}
