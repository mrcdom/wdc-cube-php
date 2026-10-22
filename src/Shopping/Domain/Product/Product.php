<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Product;

/**
 * Produto. API fluente: `campo()` lê, `withCampo()` grava e devolve $this.
 * Todos os campos são anuláveis: null = "não carregado / não projetado".
 */
final class Product
{
    private ?int $id = null;
    private ?string $name = null;
    private ?float $price = null;
    /** HTML (<ul><li>...) */
    private ?string $description = null;
    /** PNG em bytes; não vai nas projeções padrão */
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
