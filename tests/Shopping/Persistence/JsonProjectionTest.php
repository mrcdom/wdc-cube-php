<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCriteria;
use Wdc\Shopping\Domain\Purchase\PurchaseOrderBy;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemOrderBy;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Tests\Support\TestDatabase;

/**
 * A projeção em JSON traz o grafo pedido numa única ida ao banco (sem N+1) e só com os campos
 * pedidos. Estes testes contam os comandos enviados e olham o SQL gerado.
 */
final class JsonProjectionTest extends RepositoryTestCase
{
    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repos->db->onQuery(function (string $sql): void {
            $this->statements[] = $sql;
        });
    }

    /** Compras com usuário (nome) e itens (com nome do produto): três níveis de grafo. */
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
        $purchases = $this->repos->purchases->fetch(new PurchaseCriteria()
            ->withProjection(self::fullGraph())
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertCount(1, $this->statements, 'uma ida ao banco para compras + usuários + itens + produtos');
        self::assertCount(2, $purchases);

        $second = $purchases[1];
        self::assertSame('João da Silva', $second->user()?->name());
        $items = $second->items();
        self::assertIsArray($items);
        self::assertSame(['Bola Wilson', 'Fita veda rosca'], array_map(static fn (PurchaseItem $i) => $i->product()?->name(), $items));
        self::assertSame(45.3, $items[0]->price());
        self::assertNull($items[0]->product()?->price(), 'preço do produto não foi pedido');
    }

    #[Test]
    public function statementCount_doesNotGrowWithTheNumberOfRows(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->repos->purchases->insert(new Purchase()
                ->withBuyDate(new \DateTimeImmutable())
                ->withUser(new User()->withId(Seed::FULANO_ID))
                ->withItems([new PurchaseItem()->withAmount(1)->withPrice(1.0)->withProduct(new Product()->withId(Seed::CAFETEIRA_ID))]));
        }
        $this->statements = [];

        $purchases = $this->repos->purchases->fetch(new PurchaseCriteria()->withProjection(self::fullGraph()));

        self::assertCount(22, $purchases);
        self::assertCount(1, $this->statements, 'N+1 seria 1 + 22 (usuários) + 22 (itens) + ...');
    }

    #[Test]
    public function onlyTheProjectedColumnsAreSelected(): void
    {
        $this->repos->products->fetch(new ProductCriteria()->withProjection(new Product()->withName(PV::STR)));

        $field = TestDatabase::dialect() === 'pgsql'
            ? static fn (string $k, string $col): string => "'\"{$k}\":' || coalesce(to_json({$col}), 'null')"
            : static fn (string $k, string $col): string => "'{$k}', {$col}";
        self::assertStringContainsString($field('name', 'pr1.NAME'), $this->statements[0]);
        self::assertStringContainsString($field('id', 'pr1.ID'), $this->statements[0], 'o id entra sempre');
        self::assertStringNotContainsString('PRICE', $this->statements[0]);
        self::assertStringNotContainsString('DESCRIPTION', $this->statements[0]);
    }

    #[Test]
    public function relationWithOnlyTheId_comesFromTheForeignKey_withoutSubquery(): void
    {
        // a projeção padrão de compra traz user { id } — é o caso mais comum
        $purchase = $this->repos->purchases->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID);

        self::assertSame(Seed::ADMIN_ID, $purchase?->user()?->id());
        self::assertStringNotContainsString('EN_USER', $this->statements[0]);
        self::assertStringContainsString('pu1.USERID', $this->statements[0]);
    }

    #[Test]
    public function relationWithMoreThanTheId_usesACorrelatedSubquery(): void
    {
        $this->repos->purchases->fetchById(Seed::ADMIN_FIRST_PURCHASE_ID, new Purchase()->withUser(new User()->withName(PV::STR)));

        self::assertMatchesRegularExpression('/FROM EN_USER u\d+ WHERE u\d+\.ID = pu1\.USERID/', $this->statements[0]);
    }

    #[Test]
    public function collectionOrderGoesInsideTheAggregate(): void
    {
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new PurchaseItemCriteria()->withOrderBy(PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST));
        $this->repos->purchases->fetch(new PurchaseCriteria()->withProjection(new Purchase()->withItems($items)));

        $aggregate = TestDatabase::dialect() === 'pgsql' ? 'string_agg' : 'json_group_array';
        self::assertMatchesRegularExpression("/{$aggregate}\\(.+ ORDER BY pi\\d+\\.PRICE DESC, pi\\d+\\.ID ASC\\)/", $this->statements[0]);
    }

    #[Test]
    public function collectionSlice_isPerParent_inTheSameStatement(): void
    {
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new PurchaseItemCriteria()->withOrderBy(PurchaseItemOrderBy::NEWEST_FIRST))->withLimit(1);
        $purchases = $this->repos->purchases->fetch(new PurchaseCriteria()
            ->withProjection(new Purchase()->withId(PV::INT)->withItems($items))
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertCount(1, $this->statements);
        $ids = array_map(self::itemIds(...), $purchases);
        self::assertSame([[Seed::ADMIN_FIRST_PURCHASE_ITEM0_ID], [Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID]], $ids);
    }

    #[Test]
    public function collectionFilter_bindsItsParametersInTheRightPlace(): void
    {
        // o critério do filho tem parâmetro (?) dentro da subconsulta; o da raiz vem depois, no WHERE
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new PurchaseItemCriteria()->withProductId(Seed::FITA_VEDA_ROSCA_ID));
        $purchases = $this->repos->purchases->fetch(new PurchaseCriteria()
            ->withUserId(Seed::ADMIN_ID)
            ->withProjection(new Purchase()->withId(PV::INT)->withItems($items))
            ->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));

        self::assertSame([[], [Seed::ADMIN_SECOND_PURCHASE_ITEM1_ID]],
            array_map(self::itemIds(...), $purchases));
    }

    #[Test]
    public function binaryColumn_roundTripsThroughJson_andNullStaysNull(): void
    {
        $this->repos->products->updateImage(Seed::FITA_VEDA_ROSCA_ID, null);
        $products = $this->repos->products->fetch(new ProductCriteria()
            ->productId()->in([Seed::CAFETEIRA_ID, Seed::FITA_VEDA_ROSCA_ID])
            ->withProjection(new Product()->withImage(PV::BIN)));

        self::assertSame($this->repos->products->fetchImage(Seed::CAFETEIRA_ID), $products[0]->image());
        self::assertNull($products[1]->image(), 'hex(NULL) seria "" — o dialeto trata');
    }

    #[Test]
    public function criteriaOfTheWrongEntity_isRejected(): void
    {
        $items = PV::singletonList(new PurchaseItem()->withId(PV::INT), new ProductCriteria());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('não serve para EN_PURCHASEITEM');
        $this->repos->purchases->fetch(new PurchaseCriteria()->withProjection(new Purchase()->withItems($items)));
    }
}
