<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Product;

use Wdc\Framework\Domain\Criteria\ComparableCriterion;
use Wdc\Framework\Domain\Criteria\Criteria;
use Wdc\Framework\Domain\Criteria\TextCriterion;

/**
 * Critério de busca de Product. Cada campo filtrável é um Criterion que já nasce criado
 * (nunca null): use hasProductId() para saber se foi informado.
 */
final class ProductCriteria implements Criteria
{
    private ?Product $projection = null;

    private ?ProductOrderBy $orderBy = null;

    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $productId;
    /** @var TextCriterion<self> */
    private readonly TextCriterion $name;
    /** @var ComparableCriterion<self, float> */
    private readonly ComparableCriterion $price;
    /** @var TextCriterion<self> */
    private readonly TextCriterion $description;

    public function __construct()
    {
        // Em PHP o valor inicial de uma propriedade precisa ser constante;
        // objetos que dependem de $this são criados no construtor.
        $this->productId = new ComparableCriterion($this, 'productId');
        $this->name = new TextCriterion($this, 'name');
        $this->price = new ComparableCriterion($this, 'price');
        $this->description = new TextCriterion($this, 'description');
    }

    public function projection(): ?Product
    {
        return $this->projection;
    }

    public function withProjection(?Product $projection): static
    {
        $this->projection = $projection;

        return $this;
    }

    public function orderBy(): ?ProductOrderBy
    {
        return $this->orderBy;
    }

    public function withOrderBy(?ProductOrderBy $orderBy): static
    {
        $this->orderBy = $orderBy;

        return $this;
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

    /** @return TextCriterion<self> */
    public function name(): TextCriterion
    {
        return $this->name;
    }

    public function hasName(): bool
    {
        return $this->name->isSet();
    }

    /** Atalho para name()->eq($value). */
    public function withName(?string $value): static
    {
        return $value === null ? $this : $this->name->eq($value);
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

    /** Atalho para price()->eq($value). */
    public function withPrice(?float $value): static
    {
        return $value === null ? $this : $this->price->eq($value);
    }

    /** @return TextCriterion<self> */
    public function description(): TextCriterion
    {
        return $this->description;
    }

    public function hasDescription(): bool
    {
        return $this->description->isSet();
    }

    /** Atalho para description()->eq($value). */
    public function withDescription(?string $value): static
    {
        return $value === null ? $this : $this->description->eq($value);
    }

    public function criterions(): array
    {
        return [$this->productId, $this->name, $this->price, $this->description];
    }
}
