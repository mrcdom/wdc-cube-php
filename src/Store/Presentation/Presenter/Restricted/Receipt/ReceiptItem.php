<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Receipt;

use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Store\Domain\Product\Product;
use Wdc\Store\Domain\PurchaseItem\PurchaseItem;

final class ReceiptItem
{
    public int $id = -1;

    public ?string $description = null;

    public float $value = 0.0;

    public int $quantity = 0;

    public static function projection(): PurchaseItem
    {
        return new PurchaseItem()
            ->withId(PV::INT)
            ->withPrice(PV::FLOAT)
            ->withAmount(PV::INT)
            ->withProduct(new Product()->withName(PV::STR));
    }

    /** Copies the id: sorting the receipt by id depends on it. */
    public static function create(PurchaseItem $source): self
    {
        $item = new self();
        $item->id = $source->id() ?? -1;
        $item->value = $source->price() ?? 0.0;
        $item->quantity = $source->amount() ?? 0;
        $item->description = $source->product()?->name();

        return $item;
    }
}
