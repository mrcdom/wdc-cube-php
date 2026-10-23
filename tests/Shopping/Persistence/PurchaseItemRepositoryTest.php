<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemOrderBy;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;

/** Repositório de itens de compra: consultas, critérios e escrita sobre o seed. */
final class PurchaseItemRepositoryTest extends RepositoryTestCase
{
    private function repo(): PurchaseItemRepository
    {
        return Beans::of(PurchaseItemRepository::class);
    }

    #[Test]
    public function fetchAll_returnsAllSeededItems(): void
    {
        self::assertCount(3, $this->repo()->fetch(new PurchaseItemCriteria()));
    }

    #[Test]
    public function fetchById_returnsCorrectItem(): void
    {
        $item = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID);
        self::assertNotNull($item);
        self::assertSame(1, $item->amount());
        self::assertSame(200.0, $item->price());
        self::assertSame(Seed::ADMIN_FIRST_PURCHASE_ID, $item->purchaseId());
        self::assertSame(Seed::CAFETEIRA_ID, $item->productId());
    }

    #[Test]
    public function fetchById_nonExistent_returnsNull(): void
    {
        self::assertNull($this->repo()->fetchById(PHP_INT_MAX));
    }

    #[Test]
    public function fetchWithProjection_onlyRequestedFields(): void
    {
        $item = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID, new PurchaseItem()->withId(PV::INT)->withAmount(PV::INT));
        self::assertNotNull($item);
        self::assertSame(1, $item->amount());
        self::assertNull($item->price());
        self::assertNull($item->product());
    }

    #[Test]
    public function fetchWithProductName_loadsTheProduct(): void
    {
        $item = $this->repo()->fetchById(Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID, new PurchaseItem()->withProduct(new Product()->withName(PV::STR)));
        self::assertSame('Fita veda rosca', $item?->product()?->name());
    }

    #[Test]
    public function fetchByPurchaseId(): void
    {
        self::assertCount(1, $this->repo()->fetch(new PurchaseItemCriteria()->withPurchaseId(Seed::ADMIN_FIRST_PURCHASE_ID)));
        self::assertCount(2, $this->repo()->fetch(new PurchaseItemCriteria()->withPurchaseId(Seed::ADMIN_SECOND_PURCHASE_ID)));
    }

    #[Test]
    public function fetchByUserId_crossEntityExists(): void
    {
        self::assertCount(3, $this->repo()->fetch(new PurchaseItemCriteria()->withUserId(Seed::ADMIN_ID)));
        self::assertSame([], $this->repo()->fetch(new PurchaseItemCriteria()->withUserId(Seed::FULANO_ID)));
    }

    #[Test]
    public function fetchByProductId(): void
    {
        $items = $this->repo()->fetch(new PurchaseItemCriteria()->withProductId(Seed::CAFETEIRA_ID));
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertSame(Seed::CAFETEIRA_ID, $item->productId());
        }
    }

    #[Test]
    public function orderBy_mostExpensiveFirst(): void
    {
        $prices = array_map(static fn (PurchaseItem $i) => $i->price(),
            $this->repo()->fetch(new PurchaseItemCriteria()->withOrderBy(PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST)));
        self::assertSame([200.0, 45.3, 2.67], $prices);
    }

    #[Test]
    public function countByPurchaseIdAndUser(): void
    {
        self::assertSame(2, $this->repo()->count(new PurchaseItemCriteria()->withPurchaseId(Seed::ADMIN_SECOND_PURCHASE_ID)));
        self::assertSame(3, $this->repo()->count(new PurchaseItemCriteria()->withUserId(Seed::ADMIN_ID)));
        self::assertSame(0, $this->repo()->count(new PurchaseItemCriteria()->withPurchaseItemId(PHP_INT_MAX)));
    }

    #[Test]
    public function insert_newPurchaseItem(): void
    {
        $item = new PurchaseItem()
            ->withAmount(5)
            ->withPrice(15.50)
            ->withPurchase(new Purchase()->withId(Seed::ADMIN_FIRST_PURCHASE_ID))
            ->withProduct(new Product()->withId(Seed::PEN_DRIVE2GB_ID));
        self::assertTrue($this->repo()->insert($item));
        self::assertNotNull($item->id());

        $fetched = $this->repo()->fetchById($item->id());
        self::assertSame(5, $fetched?->amount());
        self::assertEqualsWithDelta(15.50, $fetched->price(), 0.001);
        self::assertSame(Seed::PEN_DRIVE2GB_ID, $fetched->productId());
    }

    #[Test]
    public function update_existingPurchaseItem(): void
    {
        $original = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID);
        self::assertNotNull($original);
        $updated = new PurchaseItem()
            ->withId($original->id())
            ->withAmount(99)
            ->withPrice(999.99)
            ->withPurchase($original->purchase())
            ->withProduct($original->product());

        self::assertTrue($this->repo()->update($updated, $original));

        $fetched = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID);
        self::assertSame(99, $fetched?->amount());
        self::assertEqualsWithDelta(999.99, $fetched->price(), 0.001);
    }

    #[Test]
    public function update_changingProduct_keepsPurchase(): void
    {
        // regressão: o update não pode gravar o productId em PURCHASEID
        $original = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID);
        self::assertNotNull($original);
        $updated = clone $original;
        $updated->withProduct(new Product()->withId(Seed::PEN_DRIVE2GB_ID));

        self::assertTrue($this->repo()->update($updated, $original));

        $fetched = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID);
        self::assertSame(Seed::PEN_DRIVE2GB_ID, $fetched?->productId());
        self::assertSame(Seed::ADMIN_FIRST_PURCHASE_ID, $fetched->purchaseId());
    }

    #[Test]
    public function deleteByPurchaseItemId(): void
    {
        self::assertSame(1, $this->repo()->delete(new PurchaseItemCriteria()->withPurchaseItemId(Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID)));
        self::assertSame(2, $this->repo()->count(new PurchaseItemCriteria()));
    }

    #[Test]
    public function deleteByPurchaseId(): void
    {
        self::assertSame(2, $this->repo()->delete(new PurchaseItemCriteria()->withPurchaseId(Seed::ADMIN_SECOND_PURCHASE_ID)));
        self::assertSame(1, $this->repo()->count(new PurchaseItemCriteria()));
    }

    #[Test]
    public function deleteByUserId_crossEntityExists(): void
    {
        self::assertSame(3, $this->repo()->delete(new PurchaseItemCriteria()->withUserId(Seed::ADMIN_ID)));
        self::assertSame(0, $this->repo()->delete(new PurchaseItemCriteria()->withUserId(Seed::FULANO_ID)));
    }

    #[Test]
    public function filterByAmount(): void
    {
        self::assertCount(3, $this->repo()->fetch(new PurchaseItemCriteria()->amount()->ge(1)));
        self::assertCount(0, $this->repo()->fetch(new PurchaseItemCriteria()->amount()->ge(2)));
    }

    #[Test]
    public function filterByPrice_and_amount_combineWithAnd(): void
    {
        $criteria = new PurchaseItemCriteria();
        $criteria->price()->gt(3.0);
        $criteria->amount()->eq(1);
        self::assertCount(2, $this->repo()->fetch($criteria));
    }
}
