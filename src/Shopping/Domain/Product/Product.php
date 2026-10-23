<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Product;

/**
 * Product. Fluent API: `field()` reads, `withField()` writes and returns $this.
 * Every field is nullable: null = "not loaded / not projected".
 */
final class Product
{
    private ?int $id = null;
    private ?string $name = null;
    private ?float $price = null;
    /** HTML (<ul><li>...) */
    private ?string $description = null;
    /** PNG bytes; not included in the default projections */
    private ?string $image = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function withId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function withName(?string $name): static
    {
        $this->name = $name;

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

    public function description(): ?string
    {
        return $this->description;
    }

    public function withDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function image(): ?string
    {
        return $this->image;
    }

    public function withImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }
}
