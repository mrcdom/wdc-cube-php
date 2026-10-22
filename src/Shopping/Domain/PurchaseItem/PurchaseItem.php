<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\PurchaseItem;

use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Purchase\Purchase;

final class PurchaseItem
{
    private ?int $id = null;
    private ?int $amount = null;
    /** preço unitário no momento da compra */
    private ?float $price = null;
    private ?Purchase $purchase = null;
    private ?Product $product = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function withId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function amount(): ?int
    {
        return $this->amount;
    }

    public function withAmount(?int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function price(): ?float
    {
        return $this->price;
    }

    public function withPrice(?float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function purchase(): ?Purchase
    {
        return $this->purchase;
    }

    public function withPurchase(?Purchase $purchase): static
    {
        $this->purchase = $purchase;

        return $this;
    }

    public function product(): ?Product
    {
        return $this->product;
    }

    public function withProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function purchaseId(): ?int
    {
        return $this->purchase?->id();
    }

    public function productId(): ?int
    {
        return $this->product?->id();
    }
}
