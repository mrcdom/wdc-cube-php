<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;

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

    /** Copia o id: a ordenação do recibo por id depende dele. */
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
