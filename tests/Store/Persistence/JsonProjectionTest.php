<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Persistence;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Store\Domain\Product\Product;
use Wdc\Store\Domain\Product\ProductCriteria;
use Wdc\Store\Domain\Product\ProductRepository;
use Wdc\Store\Domain\PurchaseItem\PurchaseItem;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemOrderBy;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Store\Domain\Purchase\Purchase;
use Wdc\Store\Domain\Purchase\PurchaseCriteria;
use Wdc\Store\Domain\Purchase\PurchaseOrderBy;
use Wdc\Store\Domain\Purchase\PurchaseRepository;
use Wdc\Store\Domain\User\User;
use Wdc\Store\Domain\User\UserRepository;
use Wdc\Store\Persistence\StoreSchema as Seed;

/**
 * The JSON projection brings the requested graph in a single database round trip (no N+1) and only with
 * the requested fields. These tests count the statements sent and inspect the generated SQL.
 */
final class JsonProjectionTest extends RepositoryTestCase
{
    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->onQuery(function (string $sql): void {
            $this->statements[] = $sql;
        });
    }

    /** Purchases with user (name) and items (with product name): three graph levels. */
    private static function fullGraph(): Purchase
    {
        return new Purchase()
            ->withId(PV::INT)
            ->withBuyDate(PV::dateTime())
            ->withUser(new User()->withId(PV::INT)->withName(PV::STR))
            ->withItems([
                new PurchaseItem()->withId(PV::INT)->withAmount(PV::INT)->withPrice(PV::FLOAT)
                    ->withProduct(new Product()->withId(PV::INT)->withName(PV::STR)),
            ]);
    }

    /** @return list<?int> */
    private static function itemIds(Purchase $purchase): array
    {
        $items = $purchase->items();
        self::assertIsArray($items);

        return array_map(static fn (PurchaseItem $i) => $i->id(), $items);
    }

    #[Test]
    public function wholeGraph_inASingleStatement(): void
    {
        $purchases = Beans::of(PurchaseRepository::class)->fetch(new PurchaseCriteria()
            ->withProjection(self::fullGraph())
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertCount(1, $this->statements, 'one round trip for purchases + users + items + products');
        self::assertCount(2, $purchases);

        $second = $purchases[1];
        self::assertSame('John Smith', $second->user()?->name());
        $items = $second->items();
        self::assertIsArray($items);
        self::assertSame(['Wilson ball', 'Thread seal tape'], array_map(static fn (PurchaseItem $i) => $i->product()?->name(), $items));
        self::assertSame(45.3, $items[0]->price());
        self::assertNull($items[0]->product()?->price(), 'product price was not requested');
    }

    #[Test]
    public function relatedRepositories_areLookedUpOnce(): void
    {
        $purchases = Beans::of(PurchaseRepository::class);
        $criteria = static fn () => new PurchaseCriteria()->withProjection(self::fullGraph());
        $purchases->fetch($criteria());

        // without the registrations a new lookup would fail: each repository kept what it found in Beans
        Beans::set(UserRepository::class, null);
        Beans::set(PurchaseItemRepository::class, null);
        Beans::set(ProductRepository::class, null);

        self::assertSame('John Smith', $purchases->fetch($criteria())[1]->user()?->name());
    }

    #[Test]
    public function statementCount_doesNotGrowWithTheNumberOfRows(): void
    {
        for ($i = 0; $i < 20; $i++) {
            Beans::of(PurchaseRepository::class)->insert(new Purchase()
                ->withBuyDate(new \DateTimeImmutable())
                ->withUser(new User()->withId(Seed::JOHN_ID))
                ->withItems([new PurchaseItem()->withAmount(1)->withPrice(1.0)->withProduct(new Product()->withId(Seed::COFFEE_MAKER_ID))]));
        }
        $this->statements = [];

        $purchases = Beans::of(PurchaseRepository::class)->fetch(new PurchaseCriteria()->withProjection(self::fullGraph()));

        self::assertCount(22, $purchases);
        self::assertCount(1, $this->statements, 'N+1 would be 1 + 22 (users) + 22 (items) + ...');
    }

    #[Test]
    public function onlyTheProjectedColumnsAreSelected(): void
    {
        Beans::of(ProductRepository::class)->fetch(new ProductCriteria()->withProjection(new Product()->withName(PV::STR)));

        $field = static fn (string $k, string $col): string => "'\"{$k}\":' || coalesce(to_json({$col}), 'null')";
        self::assertStringContainsString($field('name', 'pr1.NAME'), $this->statements[0]);
        self::assertStringContainsString($field('id', 'pr1.ID'), $this->statements[0], 'the id is always included');
        self::assertStringNotContainsString('PRICE', $this->statements[0]);
        self::assertStringNotContainsString('DESCRIPTION', $this->statements[0]);
    }

    #[Test]
    public function relationWithOnlyTheId_comesFromTheForeignKey_withoutSubquery(): void
    {
        // the default purchase projection brings user { id } — the most common case
        $purchase = Beans::of(PurchaseRepository::class)->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID);

        self::assertSame(Seed::ADMIN_ID, $purchase?->user()?->id());
        self::assertStringNotContainsString('EN_USER', $this->statements[0]);
        self::assertStringContainsString('pu1.USERID', $this->statements[0]);
    }

    #[Test]
    public function relationWithMoreThanTheId_usesACorrelatedSubquery(): void
    {
        Beans::of(PurchaseRepository::class)->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID, new Purchase()->withUser(new User()->withName(PV::STR)));

        self::assertMatchesRegularExpression('/FROM EN_USER u\d+ WHERE u\d+\.ID = pu1\.USERID/', $this->statements[0]);
    }

    #[Test]
    public function collectionOrderGoesInsideTheAggregate(): void
    {
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new PurchaseItemCriteria()->withOrderBy(PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST));
        Beans::of(PurchaseRepository::class)->fetch(new PurchaseCriteria()->withProjection(new Purchase()->withItems($items)));

        self::assertMatchesRegularExpression("/string_agg\\(.+ ORDER BY pi\\d+\\.PRICE DESC, pi\\d+\\.ID ASC\\)/", $this->statements[0]);
    }

    #[Test]
    public function collectionSlice_isPerParent_inTheSameStatement(): void
    {
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new PurchaseItemCriteria()->withOrderBy(PurchaseItemOrderBy::NEWEST_FIRST))->withLimit(1);
        $purchases = Beans::of(PurchaseRepository::class)->fetch(new PurchaseCriteria()
            ->withProjection(new Purchase()->withId(PV::INT)->withItems($items))
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertCount(1, $this->statements);
        $ids = array_map(self::itemIds(...), $purchases);
        self::assertSame([[Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID], [Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID]], $ids);
    }

    #[Test]
    public function collectionFilter_bindsItsParametersInTheRightPlace(): void
    {
        // the child criteria has a parameter (?) inside the subquery; the root one comes later, in the WHERE
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new PurchaseItemCriteria()->withProductId(Seed::THREAD_SEAL_TAPE_ID));
        $purchases = Beans::of(PurchaseRepository::class)->fetch(new PurchaseCriteria()
            ->withUserId(Seed::ADMIN_ID)
            ->withProjection(new Purchase()->withId(PV::INT)->withItems($items))
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertSame([[], [Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID]],
            array_map(self::itemIds(...), $purchases));
    }

    #[Test]
    public function binaryColumn_roundTripsThroughJson_andNullStaysNull(): void
    {
        Beans::of(ProductRepository::class)->updateImage(Seed::THREAD_SEAL_TAPE_ID, null);
        $products = Beans::of(ProductRepository::class)->fetch(new ProductCriteria()
            ->productId()->in([Seed::COFFEE_MAKER_ID, Seed::THREAD_SEAL_TAPE_ID])
            ->withProjection(new Product()->withImage(PV::BIN)));

        self::assertSame(Beans::of(ProductRepository::class)->fetchImage(Seed::COFFEE_MAKER_ID), $products[0]->image());
        self::assertNull($products[1]->image(), 'hex(NULL) would be "" — the dialect handles it');
    }

    #[Test]
    public function criteriaOfTheWrongEntity_isRejected(): void
    {
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new ProductCriteria());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not apply to EN_PURCHASEITEM');
        Beans::of(PurchaseRepository::class)->fetch(new PurchaseCriteria()->withProjection(new Purchase()->withItems($items)));
    }
}
