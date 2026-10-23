<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionList;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemOrderBy;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCriteria;
use Wdc\Shopping\Domain\Purchase\PurchaseOrderBy;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;

/** Repositório de compras: consultas, critérios, projeções, fusos e escrita sobre o seed. */
final class PurchaseRepositoryTest extends RepositoryTestCase
{
    private function repo(): PurchaseRepository
    {
        return Beans::of(PurchaseRepository::class);
    }

    private static function projectionWithUser(): Purchase
    {
        return new Purchase()->withId(PV::INT)->withBuyDate(PV::dateTime())->withUser(new User()->withId(PV::INT));
    }

    private static function itemProjection(): PurchaseItem
    {
        return new PurchaseItem()->withId(PV::INT)->withAmount(PV::INT)->withProduct(new Product()->withId(PV::INT));
    }

    /**
     * @param list<PurchaseItem>|ProjectionList<PurchaseItem> $items
     * @return list<PurchaseItem>
     */
    private function itemsOfSecondPurchase(array|ProjectionList $items): array
    {
        $purchase = $this->repo()->fetchById(Seed::ADMIN_SECOND_PURCHASE_ID, new Purchase()->withId(PV::INT)->withItems($items));
        self::assertNotNull($purchase);
        $result = $purchase->items();
        self::assertIsArray($result);

        return $result;
    }

    /** @return ProjectionList<PurchaseItem> */
    private static function items(PurchaseItemOrderBy $order): ProjectionList
    {
        return PV::singletonList(self::itemProjection(), new PurchaseItemCriteria()->withOrderBy($order));
    }

    /**
     * @param list<PurchaseItem> $items
     * @return list<?int>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn (PurchaseItem $i) => $i->id(), $items);
    }

    /** @return list<PurchaseItem> */
    private static function listOf(Purchase $purchase): array
    {
        $items = $purchase->items();
        self::assertIsArray($items, 'resultado é sempre lista, nunca ProjectionList');

        return $items;
    }

    private static function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    #[Test]
    public function fetchAll_returnsSeededPurchases(): void
    {
        self::assertCount(2, $this->repo()->fetch(new PurchaseCriteria()));
    }

    #[Test]
    public function fetchById_returnsCorrectPurchase(): void
    {
        $purchase = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID, self::projectionWithUser());
        self::assertNotNull($purchase);
        self::assertEquals(self::utc('2010-01-01 14:30:00'), $purchase->buyDate());
        self::assertSame(Seed::ADMIN_ID, $purchase->user()?->id());
        self::assertNull($purchase->user()->name(), 'só o id do usuário foi pedido');
    }

    #[Test]
    public function fetchById_nonExistent_returnsNull(): void
    {
        self::assertNull($this->repo()->fetchById(PHP_INT_MAX));
    }

    #[Test]
    public function fetchWithUserName_loadsTheUser(): void
    {
        $prj = new Purchase()->withId(PV::INT)->withUser(new User()->withId(PV::INT)->withName(PV::STR));
        $purchase = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID, $prj);
        self::assertSame('João da Silva', $purchase?->user()?->name());
    }

    #[Test]
    public function fetchWithItemsAndProductNames_buildsTheGraph(): void
    {
        $item = new PurchaseItem()->withId(PV::INT)->withPrice(PV::FLOAT)
            ->withProduct(new Product()->withId(PV::INT)->withName(PV::STR));
        $purchases = $this->repo()->fetch(new PurchaseCriteria()
            ->withProjection(new Purchase()->withId(PV::INT)->withItems([$item]))
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertCount(2, $purchases);
        $second = $purchases[1]->items();
        self::assertIsArray($second);
        self::assertSame(['Bola Wilson', 'Fita veda rosca'], array_map(static fn (PurchaseItem $i) => $i->product()?->name(), $second));
    }

    #[Test]
    public function fetchByUserId(): void
    {
        $purchases = $this->repo()->fetch(new PurchaseCriteria()->withUserId(Seed::ADMIN_ID)->withProjection(self::projectionWithUser()));
        self::assertCount(2, $purchases);
        foreach ($purchases as $p) {
            self::assertSame(Seed::ADMIN_ID, $p->user()?->id());
        }
    }

    #[Test]
    public function fetchByUserId_noResults(): void
    {
        self::assertSame([], $this->repo()->fetch(new PurchaseCriteria()->withUserId(Seed::FULANO_ID)));
    }

    #[Test]
    public function fetchByProductId_usesExists(): void
    {
        $purchases = $this->repo()->fetch(new PurchaseCriteria()->withProductId(Seed::BOLA_WILSON_ID));
        self::assertCount(1, $purchases);
        self::assertSame(Seed::ADMIN_SECOND_PURCHASE_ID, $purchases[0]->id());
    }

    #[Test]
    public function fetchWithOffsetAndLimit(): void
    {
        self::assertCount(1, $this->repo()->fetch(new PurchaseCriteria()->withOrderBy(PurchaseOrderBy::OLDEST_FIRST), 0, 1));
    }

    #[Test]
    public function countByUserId(): void
    {
        self::assertSame(2, $this->repo()->count(new PurchaseCriteria()->withUserId(Seed::ADMIN_ID)));
        self::assertSame(0, $this->repo()->count(new PurchaseCriteria()->withPurchaseId(PHP_INT_MAX)));
    }

    #[Test]
    public function insert_newPurchase(): void
    {
        $purchase = new Purchase()->withBuyDate(new DateTimeImmutable())->withUser(new User()->withId(Seed::FULANO_ID));
        self::assertTrue($this->repo()->insert($purchase));
        self::assertSame(2, $purchase->id());
        self::assertSame(Seed::FULANO_ID, $this->repo()->fetchById($purchase->id(), self::projectionWithUser())?->user()?->id());
    }

    #[Test]
    public function insert_withItems_insertsEverything(): void
    {
        $purchase = new Purchase()
            ->withBuyDate(new DateTimeImmutable())
            ->withUser(new User()->withId(Seed::FULANO_ID))
            ->withItems([
                new PurchaseItem()->withAmount(2)->withPrice(16.0)->withProduct(new Product()->withId(Seed::PEN_DRIVE2GB_ID)),
                new PurchaseItem()->withAmount(1)->withPrice(2.67)->withProduct(new Product()->withId(Seed::FITA_VEDA_ROSCA_ID)),
            ]);
        self::assertTrue($this->repo()->insert($purchase));

        $items = Beans::of(PurchaseItemRepository::class)->fetch(new PurchaseItemCriteria()->withPurchaseId($purchase->id()));
        self::assertCount(2, $items);
        self::assertSame([3, 4], self::ids($items), 'ids gerados pela sequência de itens');
    }

    #[Test]
    public function insert_keepsTheInstantAcrossTimezones(): void
    {
        $saoPaulo = new DateTimeImmutable('2024-05-10 21:00:00', new DateTimeZone('America/Sao_Paulo'));
        $purchase = new Purchase()->withBuyDate($saoPaulo)->withUser(new User()->withId(Seed::FULANO_ID));
        $this->repo()->insert($purchase);

        $fetched = $this->repo()->fetchById($purchase->id());
        self::assertEquals($saoPaulo, $fetched?->buyDate(), 'mesmo instante, sem perder o fuso');
    }

    #[Test]
    public function update_existingPurchase(): void
    {
        $original = $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID, self::projectionWithUser());
        self::assertNotNull($original);
        $updated = new Purchase()->withId($original->id())->withBuyDate(new DateTimeImmutable())->withUser(new User()->withId(Seed::BEOTRANO_ID));

        self::assertTrue($this->repo()->update($updated, $original));
        self::assertSame(Seed::BEOTRANO_ID, $this->repo()->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID, self::projectionWithUser())?->user()?->id());
    }

    #[Test]
    public function deleteByPurchaseId_alsoDeletesItems(): void
    {
        self::assertSame(1, $this->repo()->delete(new PurchaseCriteria()->withPurchaseId(Seed::ADMIN_FIRST_PURCHASE_ID)));
        self::assertSame(1, $this->repo()->count(new PurchaseCriteria()));
        self::assertSame(2, Beans::of(PurchaseItemRepository::class)->count(new PurchaseItemCriteria()));
    }

    #[Test]
    public function deleteByUserId(): void
    {
        self::assertSame(2, $this->repo()->delete(new PurchaseCriteria()->withUserId(Seed::ADMIN_ID)));
        self::assertSame(0, $this->repo()->count(new PurchaseCriteria()));
    }

    #[Test]
    public function collection_withoutOrderOrSlice_bringsEveryItem(): void
    {
        self::assertCount(2, $this->itemsOfSecondPurchase(PV::singletonList(self::itemProjection(), new PurchaseItemCriteria())));
    }

    #[Test]
    public function collection_plainArray_bringsEveryItem(): void
    {
        self::assertCount(2, $this->itemsOfSecondPurchase([self::itemProjection()]));
    }

    #[Test]
    public function collection_ascending_orders(): void
    {
        self::assertSame(
            [Seed::ADMIN_SECOND_PURCHASE_ITEM0_ID, Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID],
            self::ids($this->itemsOfSecondPurchase(self::items(PurchaseItemOrderBy::OLDEST_FIRST))),
        );
    }

    #[Test]
    public function collection_descending_reverses(): void
    {
        self::assertSame(
            [Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID, Seed::ADMIN_SECOND_PURCHASE_ITEM0_ID],
            self::ids($this->itemsOfSecondPurchase(self::items(PurchaseItemOrderBy::NEWEST_FIRST))),
        );
    }

    #[Test]
    public function collection_limit_cuts(): void
    {
        self::assertSame([Seed::ADMIN_SECOND_PURCHASE_ITEM0_ID],
            self::ids($this->itemsOfSecondPurchase(self::items(PurchaseItemOrderBy::OLDEST_FIRST)->withLimit(1))));
    }

    #[Test]
    public function collection_limit_respectsOrder(): void
    {
        self::assertSame([Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID],
            self::ids($this->itemsOfSecondPurchase(self::items(PurchaseItemOrderBy::NEWEST_FIRST)->withLimit(1))));
    }

    #[Test]
    public function collection_offset_skips(): void
    {
        self::assertSame([Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID],
            self::ids($this->itemsOfSecondPurchase(self::items(PurchaseItemOrderBy::OLDEST_FIRST)->withOffset(1))));
    }

    #[Test]
    public function collection_limitAndOffset(): void
    {
        self::assertSame([Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID],
            self::ids($this->itemsOfSecondPurchase(self::items(PurchaseItemOrderBy::OLDEST_FIRST)->withOffset(1)->withLimit(1))));
    }

    #[Test]
    public function collection_offsetBeyondEnd_bringsNothing(): void
    {
        self::assertSame([], $this->itemsOfSecondPurchase(self::items(PurchaseItemOrderBy::OLDEST_FIRST)->withOffset(10)));
    }

    #[Test]
    public function collection_limitIsPerParent(): void
    {
        $purchases = $this->repo()->fetch(new PurchaseCriteria()
            ->withProjection(new Purchase()->withId(PV::INT)->withItems(self::items(PurchaseItemOrderBy::OLDEST_FIRST)->withLimit(1)))
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertSame([Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID], self::ids(self::listOf($purchases[0])));
        self::assertSame([Seed::ADMIN_SECOND_PURCHASE_ITEM0_ID], self::ids(self::listOf($purchases[1])));
    }

    #[Test]
    public function collection_filtersBySubCriteria(): void
    {
        $result = $this->itemsOfSecondPurchase(PV::singletonList(self::itemProjection(), new PurchaseItemCriteria()->withProductId(Seed::BOLA_WILSON_ID)));
        self::assertCount(1, $result);
        self::assertSame(Seed::BOLA_WILSON_ID, $result[0]->product()?->id());
    }

    #[Test]
    public function collection_subCriteriaComposesWithSlice(): void
    {
        $criteria = new PurchaseItemCriteria()->withProductId(Seed::BOLA_WILSON_ID)->withOrderBy(PurchaseItemOrderBy::OLDEST_FIRST);
        self::assertSame([Seed::ADMIN_SECOND_PURCHASE_ITEM0_ID],
            self::ids($this->itemsOfSecondPurchase(PV::singletonList(self::itemProjection(), $criteria)->withLimit(5))));
    }

    #[Test]
    public function filterByBuyDate_range(): void
    {
        $purchases = $this->repo()->fetch(new PurchaseCriteria()->buyDate()->between(self::utc('2010-01-01T00:00:00Z'), self::utc('2011-12-31T23:59:59Z')));
        self::assertCount(2, $purchases, 'as duas compras do seed estão nesse intervalo');
    }

    #[Test]
    public function filterByBuyDate_before(): void
    {
        $purchases = $this->repo()->fetch(new PurchaseCriteria()->buyDate()->lt(self::utc('2011-01-01T00:00:00Z')));
        self::assertCount(1, $purchases);
        self::assertSame(Seed::ADMIN_FIRST_PURCHASE_ID, $purchases[0]->id());
    }

    #[Test]
    public function orderBy_mostRecentAndEarliest_areOpposites(): void
    {
        $ids = fn (PurchaseOrderBy $o): array => array_map(
            static fn (Purchase $p) => $p->id(),
            $this->repo()->fetch(new PurchaseCriteria()->withOrderBy($o)),
        );
        self::assertSame([1, 0], $ids(PurchaseOrderBy::MOST_RECENT_PURCHASE_FIRST));
        self::assertSame([0, 1], $ids(PurchaseOrderBy::EARLIEST_PURCHASE_FIRST));
    }
}
