<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Purchase;

use DateTimeImmutable;
use Wdc\Framework\Domain\Criteria\ComparableCriterion;
use Wdc\Framework\Domain\Criteria\Criteria;

/**
 * Critério de busca de Purchase. Cada campo filtrável é um Criterion que já nasce criado
 * (nunca null): use hasPurchaseId() para saber se foi informado.
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
    /** @var ComparableCriterion<self, int>  campo virtual: existe item com este produto (EXISTS) */
    private readonly ComparableCriterion $productId;

    public function __construct()
    {
        // Em PHP o valor inicial de uma propriedade precisa ser constante;
        // objetos que dependem de $this são criados no construtor.
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

    /** Atalho para purchaseId()->eq($value). */
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

    /** Atalho para userId()->eq($value). */
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

    /** Atalho para buyDate()->eq($value). */
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

    /** Atalho para productId()->eq($value). */
    public function withProductId(?int $value): static
    {
        return $value === null ? $this : $this->productId->eq($value);
    }

    public function criterions(): array
    {
        return [$this->purchaseId, $this->userId, $this->buyDate, $this->productId];
    }
}
