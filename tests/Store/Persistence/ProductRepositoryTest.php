<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Persistence;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Exception\BusinessException;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Store\Domain\Product\Product;
use Wdc\Store\Domain\Product\ProductCriteria;
use Wdc\Store\Domain\Product\ProductOrderBy;
use Wdc\Store\Domain\Product\ProductRepository;
use Wdc\Store\Persistence\StoreSchema as Seed;

/** Product repository: queries, criteria, projections and writes over the seed. */
final class ProductRepositoryTest extends RepositoryTestCase
{
    private function repo(): ProductRepository
    {
        return Beans::of(ProductRepository::class);
    }

    /** @return list<?string> */
    private function namesOrderedBy(ProductOrderBy $order): array
    {
        return array_map(static fn (Product $p) => $p->name(), $this->repo()->fetch(new ProductCriteria()->withOrderBy($order)));
    }

    /** @return list<?float> */
    private function pricesOrderedBy(ProductOrderBy $order): array
    {
        return array_map(static fn (Product $p) => $p->price(), $this->repo()->fetch(new ProductCriteria()->withOrderBy($order)));
    }

    /** @return list<?int> */
    private function idsOrderedBy(ProductOrderBy $order): array
    {
        return array_map(static fn (Product $p) => $p->id(), $this->repo()->fetch(new ProductCriteria()->withOrderBy($order)));
    }

    #[Test]
    public function fetchAll_returnsFourProducts(): void
    {
        self::assertCount(4, $this->repo()->fetch(new ProductCriteria()));
    }

    #[Test]
    public function fetchById_returnsCorrectProduct(): void
    {
        $product = $this->repo()->fetchById(Seed::COFFEE_MAKER_ID);
        self::assertNotNull($product);
        self::assertSame('Italian design coffee maker', $product->name());
        self::assertSame(199.99, $product->price());
        self::assertNull($product->image(), 'image is not in the default projection');
    }

    #[Test]
    public function fetchById_nonExistent_returnsNull(): void
    {
        self::assertNull($this->repo()->fetchById(PHP_INT_MAX));
    }

    #[Test]
    public function fetchWithProjection_onlyRequestedFields(): void
    {
        $product = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID, new Product()->withId(PV::INT)->withName(PV::STR));
        self::assertNotNull($product);
        self::assertSame(Seed::FLASH_DRIVE2GB_ID, $product->id());
        self::assertNotNull($product->name());
        self::assertNull($product->price());
        self::assertNull($product->description());
    }

    #[Test]
    public function fetchByCriteria_productId(): void
    {
        $products = $this->repo()->fetch(new ProductCriteria()->withProductId(Seed::WILSON_BALL_ID));
        self::assertCount(1, $products);
        self::assertSame(Seed::WILSON_BALL_ID, $products[0]->id());
    }

    #[Test]
    public function fetchWithOffsetAndLimit(): void
    {
        $products = $this->repo()->fetch(new ProductCriteria()->withOrderBy(ProductOrderBy::OLDEST_FIRST), 1, 2);
        self::assertSame([1, 2], array_map(static fn (Product $p) => $p->id(), $products));
    }

    #[Test]
    public function fetchWithOffsetOnly(): void
    {
        self::assertCount(3, $this->repo()->fetch(new ProductCriteria()->withOrderBy(ProductOrderBy::OLDEST_FIRST), 1));
    }

    #[Test]
    public function fetchPage_computesTotals(): void
    {
        $page = $this->repo()->fetchPage(new ProductCriteria()->withOrderBy(ProductOrderBy::OLDEST_FIRST), 1, 3);
        self::assertSame(4, $page->totalItems);
        self::assertSame(2, $page->totalPages);
        self::assertCount(1, $page->items);
        self::assertSame(Seed::FLASH_DRIVE2GB_ID, $page->items[0]->id());
    }

    #[Test]
    public function orderBy_oldestAndNewest_areOpposites(): void
    {
        self::assertSame(
            array_reverse($this->idsOrderedBy(ProductOrderBy::OLDEST_FIRST)),
            $this->idsOrderedBy(ProductOrderBy::NEWEST_FIRST),
        );
    }

    #[Test]
    public function countAll_returnsFour(): void
    {
        self::assertSame(4, $this->repo()->count(new ProductCriteria()));
    }

    #[Test]
    public function countByProductId_returnsOne(): void
    {
        self::assertSame(1, $this->repo()->count(new ProductCriteria()->withProductId(Seed::COFFEE_MAKER_ID)));
    }

    #[Test]
    public function countNonExistent_returnsZero(): void
    {
        self::assertSame(0, $this->repo()->count(new ProductCriteria()->withProductId(PHP_INT_MAX)));
    }

    #[Test]
    public function fetchImage_returnsPngForSeededProduct(): void
    {
        $image = $this->repo()->fetchImage(Seed::COFFEE_MAKER_ID);
        self::assertNotNull($image);
        self::assertStringStartsWith("\x89PNG", $image);
    }

    #[Test]
    public function fetchImage_nonExistent_throws(): void
    {
        $this->expectException(BusinessException::class);
        $this->repo()->fetchImage(PHP_INT_MAX);
    }

    #[Test]
    public function fetchWithImageProjection_bringsBytes(): void
    {
        $product = $this->repo()->fetchById(Seed::WILSON_BALL_ID, new Product()->withImage(PV::BIN));
        self::assertNotNull($product);
        self::assertStringStartsWith("\x89PNG", (string) $product->image());
    }

    #[Test]
    public function updateImage_replacesBytes(): void
    {
        self::assertTrue($this->repo()->updateImage(Seed::WILSON_BALL_ID, "\x00\x01\x02"));
        self::assertSame("\x00\x01\x02", $this->repo()->fetchImage(Seed::WILSON_BALL_ID));
    }

    #[Test]
    public function insert_newProduct(): void
    {
        $product = new Product()->withName('USB keyboard')->withPrice(89.90)->withDescription('Mechanical keyboard');
        self::assertTrue($this->repo()->insert($product));
        self::assertSame(4, $product->id(), 'id generated by the sequence (the seed restarts it at 4)');

        $fetched = $this->repo()->fetchById($product->id());
        self::assertNotNull($fetched);
        self::assertSame('USB keyboard', $fetched->name());
        self::assertEqualsWithDelta(89.90, $fetched->price(), 0.001);
        self::assertStringContainsString('keyboard', (string) $fetched->description());
    }

    #[Test]
    public function update_existingProduct(): void
    {
        $original = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID);
        self::assertNotNull($original);
        $updated = new Product()
            ->withId($original->id())
            ->withName('Flash drive 4GB')
            ->withPrice(35.0)
            ->withDescription($original->description());

        self::assertTrue($this->repo()->update($updated, $original));

        $fetched = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID);
        self::assertSame('Flash drive 4GB', $fetched?->name());
        self::assertEqualsWithDelta(35.0, $fetched->price(), 0.001);
    }

    #[Test]
    public function update_withoutChanges_returnsFalse(): void
    {
        $original = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID);
        self::assertNotNull($original);
        self::assertFalse($this->repo()->update(clone $original, $original));
    }

    #[Test]
    public function update_partialFields_onlyChangesSpecifiedFields(): void
    {
        $original = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID);
        self::assertNotNull($original);
        $updated = new Product()->withId($original->id())->withName('Flash drive renamed');

        self::assertTrue($this->repo()->update($updated, $original, new Product()->withId(PV::INT)->withName(PV::STR)));

        $fetched = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID);
        self::assertSame('Flash drive renamed', $fetched?->name());
        self::assertSame($original->price(), $fetched->price());
        self::assertSame($original->description(), $fetched->description());
    }

    #[Test]
    public function update_nullOutsideProjection_isIgnored(): void
    {
        $original = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID);
        self::assertNotNull($original);
        $updated = new Product()->withId($original->id())->withPrice(99.99)->withDescription(null);

        self::assertTrue($this->repo()->update($updated, $original, new Product()->withId(PV::INT)->withPrice(PV::FLOAT)));

        $fetched = $this->repo()->fetchById(Seed::FLASH_DRIVE2GB_ID);
        self::assertSame($original->description(), $fetched?->description());
        self::assertEqualsWithDelta(99.99, $fetched?->price(), 0.001);
    }

    #[Test]
    public function deleteByProductId(): void
    {
        self::assertSame(1, $this->repo()->delete(new ProductCriteria()->withProductId(Seed::FLASH_DRIVE2GB_ID)));
        self::assertSame(3, $this->repo()->count(new ProductCriteria()));
    }

    #[Test]
    public function deleteNonExistent_returnsZero(): void
    {
        self::assertSame(0, $this->repo()->delete(new ProductCriteria()->withProductId(PHP_INT_MAX)));
    }

    #[Test]
    public function deleteWithEmptyCriteria_isRefused(): void
    {
        try {
            $this->repo()->delete(new ProductCriteria());
            self::fail('delete without a key should be refused');
        } catch (LogicException) {   // PHP 8: catch without a variable when it is not used
            self::assertSame(4, $this->repo()->count(new ProductCriteria()));
        }
    }

    #[Test]
    public function in_bringsOnlyTheListedOnes(): void
    {
        self::assertCount(2, $this->repo()->fetch(new ProductCriteria()->productId()->in([Seed::COFFEE_MAKER_ID, Seed::FLASH_DRIVE2GB_ID])));
    }

    #[Test]
    public function in_emptyList_doesNotFilter(): void
    {
        self::assertCount(4, $this->repo()->fetch(new ProductCriteria()->productId()->in([])));
    }

    #[Test]
    public function between_isInclusiveOnBothEnds(): void
    {
        self::assertCount(3, $this->repo()->fetch(new ProductCriteria()->productId()->between(Seed::WILSON_BALL_ID, Seed::FLASH_DRIVE2GB_ID)));
    }

    #[Test]
    public function ne_excludesTheOne(): void
    {
        self::assertCount(3, $this->repo()->fetch(new ProductCriteria()->productId()->ne(Seed::COFFEE_MAKER_ID)));
    }

    #[Test]
    public function twoNe_excludeBoth(): void
    {
        $criteria = new ProductCriteria();
        $criteria->productId()->ne(Seed::COFFEE_MAKER_ID);
        $criteria->productId()->ne(Seed::WILSON_BALL_ID);
        self::assertCount(2, $this->repo()->fetch($criteria));
    }

    #[Test]
    public function or_turnsRequestsIntoAlternatives(): void
    {
        $criteria = new ProductCriteria();
        $criteria->productId()->or()->eq(Seed::COFFEE_MAKER_ID);
        $criteria->productId()->eq(Seed::WILSON_BALL_ID);
        self::assertCount(2, $this->repo()->fetch($criteria));
    }

    #[Test]
    public function gt_comparesByOrder(): void
    {
        self::assertCount(2, $this->repo()->fetch(new ProductCriteria()->productId()->gt(Seed::WILSON_BALL_ID)));
    }

    #[Test]
    public function betweenWithOpenEnd_becomesASingleBound(): void
    {
        self::assertCount(2, $this->repo()->fetch(new ProductCriteria()->productId()->between(Seed::THREAD_SEAL_TAPE_ID, null)));
    }

    #[Test]
    public function isNull_and_isNotNull(): void
    {
        self::assertCount(0, $this->repo()->fetch(new ProductCriteria()->description()->isNull()));
        self::assertCount(4, $this->repo()->fetch(new ProductCriteria()->description()->isNotNull()));
    }

    #[Test]
    public function emptyCriterion_doesNotFilter(): void
    {
        $criteria = new ProductCriteria();
        $criteria->productId()->eq(null);
        self::assertCount(4, $this->repo()->fetch($criteria), 'a null value adds no request');
    }

    #[Test]
    public function clear_undoesWhatWasAsked(): void
    {
        $criteria = new ProductCriteria()->withProductId(Seed::COFFEE_MAKER_ID);
        self::assertCount(1, $this->repo()->fetch($criteria));
        $criteria->productId()->clear();
        self::assertCount(4, $this->repo()->fetch($criteria));
    }

    #[Test]
    public function filterByName_text(): void
    {
        $products = $this->repo()->fetch(new ProductCriteria()->name()->containing('wilson'));
        self::assertCount(1, $products);
        self::assertSame(Seed::WILSON_BALL_ID, $products[0]->id());
    }

    #[Test]
    public function filterByName_eq_isCaseSensitive(): void
    {
        // NAME is plain TEXT in the DDL: equality is case-sensitive
        self::assertCount(0, $this->repo()->fetch(new ProductCriteria()->withName('WILSON BALL')));
        self::assertCount(1, $this->repo()->fetch(new ProductCriteria()->withName('Wilson ball')));
    }

    #[Test]
    public function filterByPrice_range(): void
    {
        $products = $this->repo()->fetch(new ProductCriteria()->price()->between(2.0, 50.0));
        self::assertCount(3, $products);
        foreach ($products as $p) {
            self::assertGreaterThanOrEqual(2.0, $p->price());
            self::assertLessThanOrEqual(50.0, $p->price());
        }
    }

    #[Test]
    public function filterByPrice_greaterThan(): void
    {
        $products = $this->repo()->fetch(new ProductCriteria()->price()->gt(100.0));
        self::assertCount(1, $products);
        self::assertSame(Seed::COFFEE_MAKER_ID, $products[0]->id());
    }

    #[Test]
    public function filterByDescription_text(): void
    {
        $products = $this->repo()->fetch(new ProductCriteria()->description()->containing('Teflon'));
        self::assertCount(1, $products);
        self::assertSame(Seed::THREAD_SEAL_TAPE_ID, $products[0]->id());
    }

    #[Test]
    public function filterByTwoDifferentFields_combinesWithAnd(): void
    {
        $criteria = new ProductCriteria();
        $criteria->name()->containing('a');
        $criteria->price()->lt(50.0);
        $products = $this->repo()->fetch($criteria);
        self::assertNotEmpty($products);
        foreach ($products as $p) {
            self::assertStringContainsStringIgnoringCase('a', (string) $p->name());
            self::assertLessThan(50.0, $p->price());
        }
    }

    #[Test]
    public function orderBy_nameAToZ(): void
    {
        $names = $this->namesOrderedBy(ProductOrderBy::NAME_A_TO_Z);
        $sorted = $names;
        usort($sorted, static fn (?string $a, ?string $b): int => strcasecmp((string) $a, (string) $b));
        self::assertSame($sorted, $names);
        self::assertNotSame($this->namesOrderedBy(ProductOrderBy::OLDEST_FIRST), $names);
    }

    #[Test]
    public function orderBy_cheapestFirst(): void
    {
        $prices = $this->pricesOrderedBy(ProductOrderBy::CHEAPEST_FIRST);
        $sorted = $prices;
        sort($sorted);
        self::assertSame($sorted, $prices);
    }

    #[Test]
    public function orderBy_mostExpensiveFirst_isTheReverse(): void
    {
        self::assertSame(
            array_reverse($this->pricesOrderedBy(ProductOrderBy::CHEAPEST_FIRST)),
            $this->pricesOrderedBy(ProductOrderBy::MOST_EXPENSIVE_FIRST),
        );
    }
}
