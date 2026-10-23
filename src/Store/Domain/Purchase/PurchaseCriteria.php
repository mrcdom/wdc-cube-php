<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Purchase;

use DateTimeImmutable;
use Wdc\Framework\Domain\Criteria\ComparableCriterion;
use Wdc\Framework\Domain\Criteria\Criteria;

/**
 * Search criterion for Purchase. Each filterable field is a Criterion created up front
 * (never null): use hasPurchaseId() to tell whether it was set.
 */
final class PurchaseCriteria implements Criteria
{
    private ?Purchase $projection = null;

    private ?PurchaseOrderBy $orderBy = null;

    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $purchaseId;
    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $userId;
    /** @var ComparableCriterion<self, DateTimeImmutable> */
    private readonly ComparableCriterion $buyDate;
    /** @var ComparableCriterion<self, int>  virtual field: an item with this product exists (EXISTS) */
    private readonly ComparableCriterion $productId;

    public function __construct()
    {
        // In PHP a property's initial value must be a constant;
        // objects that depend on $this are created in the constructor.
        $this->purchaseId = new ComparableCriterion($this, 'purchaseId');
        $this->userId = new ComparableCriterion($this, 'userId');
        $this->buyDate = new ComparableCriterion($this, 'buyDate');
        $this->productId = new ComparableCriterion($this, 'productId');
    }

    public function projection(): ?Purchase
    {
        return $this->projection;
    }

    public function withProjection(?Purchase $projection): static
    {
        $this->projection = $projection;

        return $this;
    }

    public function orderBy(): ?PurchaseOrderBy
    {
        return $this->orderBy;
    }

    public function withOrderBy(?PurchaseOrderBy $orderBy): static
    {
        $this->orderBy = $orderBy;

        return $this;
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

    /** @return ComparableCriterion<self, DateTimeImmutable> */
    public function buyDate(): ComparableCriterion
    {
        return $this->buyDate;
    }

    public function hasBuyDate(): bool
    {
        return $this->buyDate->isSet();
    }

    /** Shortcut for buyDate()->eq($value). */
    public function withBuyDate(?DateTimeImmutable $value): static
    {
        return $value === null ? $this : $this->buyDate->eq($value);
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

    public function criterions(): array
    {
        return [$this->purchaseId, $this->userId, $this->buyDate, $this->productId];
    }
}
