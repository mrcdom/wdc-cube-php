<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Purchase;

use DateTimeImmutable;
use Wdc\Framework\Domain\Projection\ProjectionList;
use Wdc\Store\Domain\PurchaseItem\PurchaseItem;
use Wdc\Store\Domain\User\User;

final class Purchase
{
    private ?int $id = null;
    private ?DateTimeImmutable $buyDate = null;
    private ?User $user = null;
    /**
     * In results it is always a list; in a projection it may be a ProjectionList
     * (shape + criterion + slice of the items). `|` = union type.
     *
     * @var list<PurchaseItem>|ProjectionList<PurchaseItem>|null
     */
    private array|ProjectionList|null $items = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function withId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function buyDate(): ?DateTimeImmutable
    {
        return $this->buyDate;
    }

    public function withBuyDate(?DateTimeImmutable $buyDate): static
    {
        $this->buyDate = $buyDate;

        return $this;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function withUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /** @return list<PurchaseItem>|ProjectionList<PurchaseItem>|null */
    public function items(): array|ProjectionList|null
    {
        return $this->items;
    }

    /** @param list<PurchaseItem>|ProjectionList<PurchaseItem>|null $items */
    public function withItems(array|ProjectionList|null $items): static
    {
        $this->items = $items;

        return $this;
    }

    public function userId(): ?int
    {
        return $this->user?->id();   // ?-> : "nullsafe" — if user is null, the result is null
    }
}
