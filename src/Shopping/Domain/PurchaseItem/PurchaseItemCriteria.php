<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\PurchaseItem;

use Wdc\Framework\Domain\Criteria\ComparableCriterion;
use Wdc\Framework\Domain\Criteria\Criteria;

/**
 * Search criterion for PurchaseItem. Each filterable field is a Criterion created up front
 * (never null): use hasPurchaseItemId() to tell whether it was set.
 */
final class PurchaseItemCriteria implements Criteria
{
    private ?PurchaseItem $projection = null;

    private ?PurchaseItemOrderBy $orderBy = null;

    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $purchaseItemId;
    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $purchaseId;
    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $productId;
    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $amount;
    /** @var ComparableCriterion<self, float> */
    private readonly ComparableCriterion $price;
    /** @var ComparableCriterion<self, int>  virtual field: the purchase's owner (EXISTS on EN_PURCHASE) */
    private readonly ComparableCriterion $userId;

    public function __construct()
    {
        // In PHP a property's initial value must be a constant;
        // objects that depend on $this are created in the constructor.
        $this->purchaseItemId = new ComparableCriterion($this, 'purchaseItemId');
        $this->purchaseId = new ComparableCriterion($this, 'purchaseId');
        $this->productId = new ComparableCriterion($this, 'productId');
        $this->amount = new ComparableCriterion($this, 'amount');
        $this->price = new ComparableCriterion($this, 'price');
        $this->userId = new ComparableCriterion($this, 'userId');
    }

    public function projection(): ?PurchaseItem
    {
        return $this->projection;
    }

    public function withProjection(?PurchaseItem $projection): static
    {
        $this->projection = $projection;

        return $this;
    }

    public function orderBy(): ?PurchaseItemOrderBy
    {
        return $this->orderBy;
    }

    public function withOrderBy(?PurchaseItemOrderBy $orderBy): static
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    /** @return ComparableCriterion<self, int> */
    public function purchaseItemId(): ComparableCriterion
    {
        return $this->purchaseItemId;
    }

    public function hasPurchaseItemId(): bool
    {
        return $this->purchaseItemId->isSet();
    }

    /** Shortcut for purchaseItemId()->eq($value). */
    public function withPurchaseItemId(?int $value): static
    {
        return $value === null ? $this : $this->purchaseItemId->eq($value);
    }

    /** @return ComparableCriterion<self, int> */
    public function purchaseId(): ComparableCriterion
    {
        return $this->purchaseId;
    }

    public function hasPurchaseId(): bool
    {
        return $this->purchaseId->isSet();
    }

    /** Shortcut for purchaseId()->eq($value). */
    public function withPurchaseId(?int $value): static
    {
        return $value === null ? $this : $this->purchaseId->eq($value);
    }

    /** @return ComparableCriterion<self, int> */
    public function productId(): ComparableCriterion
    {
        return $this->productId;
    }

    public function hasProductId(): bool
    {
        return $this->productId->isSet();
    }

    /** Shortcut for productId()->eq($value). */
    public function withProductId(?int $value): static
    {
        return $value === null ? $this : $this->productId->eq($value);
    }

    /** @return ComparableCriterion<self, int> */
    public function amount(): ComparableCriterion
    {
        return $this->amount;
    }

    public function hasAmount(): bool
    {
        return $this->amount->isSet();
    }

    /** Shortcut for amount()->eq($value). */
    public function withAmount(?int $value): static
    {
        return $value === null ? $this : $this->amount->eq($value);
    }

    /** @return ComparableCriterion<self, float> */
    public function price(): ComparableCriterion
    {
        return $this->price;
    }

    public function hasPrice(): bool
    {
        return $this->price->isSet();
    }

    /** Shortcut for price()->eq($value). */
    public function withPrice(?float $value): static
    {
        return $value === null ? $this : $this->price->eq($value);
    }

    /** @return ComparableCriterion<self, int> */
    public function userId(): ComparableCriterion
    {
        return $this->userId;
    }

    public function hasUserId(): bool
    {
        return $this->userId->isSet();
    }

    /** Shortcut for userId()->eq($value). */
    public function withUserId(?int $value): static
    {
        return $value === null ? $this : $this->userId->eq($value);
    }

    public function criterions(): array
    {
        return [$this->purchaseItemId, $this->purchaseId, $this->productId, $this->amount, $this->price, $this->userId];
    }
}
