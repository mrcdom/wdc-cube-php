<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCriteria;
use Wdc\Shopping\Domain\Purchase\PurchaseOrderBy;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Shopping\Presentation\Presenter\Open\Login\LoginService;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelService;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductInfo;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductService;
use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptService;
use Wdc\Tests\Shopping\Persistence\RepositoryTestCase;

/** Serviço de compras testado diretamente, sem presenters. */
final class ShoppingServiceTest extends RepositoryTestCase
{
    /** Os serviços acham os repositórios no Beans; sem autenticação registrada, o login confere a senha direto. */
    protected function setUp(): void
    {
        parent::setUp();
        Beans::clear();
        $this->repos->register();
    }

    protected function tearDown(): void
    {
        Beans::clear();
    }

    #[Test]
    public function purchaseStatement_ordersByPurchaseDate_notByInsertionOrder(): void
    {
        // a última cadastrada (maior id), porém a mais antiga de todas
        $late = new Purchase()
            ->withUser(new User()->withId(Seed::ADMIN_ID))
            ->withBuyDate(new DateTimeImmutable('2009-05-20 10:00:00', new DateTimeZone('UTC')))
            ->withItems([new PurchaseItem()->withProduct(new Product()->withId(Seed::PEN_DRIVE2GB_ID))->withPrice(55.0)->withAmount(1)]);
        $this->repos->purchases->insert($late);

        $statement = new PurchasesPanelService()->loadPurchasesOfUser(Seed::ADMIN_ID);
        $ids = array_map(static fn ($p) => $p->id, $statement);
        self::assertCount(3, $ids);

        self::assertSame($late->id(), max(...$ids), 'a compra de 2009 é a de maior id');
        self::assertNotSame($late->id(), $ids[0], 'a mais antiga não pode encabeçar o extrato');
        self::assertSame($late->id(), $ids[2], 'a mais antiga fecha a lista');
    }

    #[Test]
    public function purchaseItemsOfUser_withNestedProjection(): void
    {
        $projection = new PurchaseItem()
            ->withId(PV::INT)->withAmount(PV::INT)->withPrice(PV::FLOAT)
            ->withProduct(new Product()->withId(PV::INT)->withName(PV::STR))
            ->withPurchase(new Purchase()->withId(PV::INT)->withBuyDate(PV::dateTime())
                ->withUser(new User()->withId(PV::INT)->withUserName(PV::STR)));

        $items = $this->repos->purchaseItems->fetch(new PurchaseItemCriteria()->withUserId(Seed::ADMIN_ID)->withProjection($projection));

        self::assertCount(3, $items);
        self::assertSame('admin', $items[0]->purchase()?->user()?->userName(), 'três níveis de grafo');
    }

    #[Test]
    public function login_wrongPassword_isRejected(): void
    {
        self::assertNull(new LoginService()->fetchSubject('admin', 'senha-errada'));
    }

    #[Test]
    public function login_unknownUser_isRejected(): void
    {
        self::assertNull(new LoginService()->fetchSubject('ninguem', 'admin'));
    }

    #[Test]
    public function fullServiceFlow(): void
    {
        $result = new LoginService()->fetchSubject('admin', 'admin');
        self::assertNotNull($result);
        self::assertSame(Seed::ADMIN_ID, $result->subject->id);
        self::assertSame('João da Silva', $result->subject->nickName);
        self::assertNull($result->persistentToken, 'sem AuthenticationService não há "lembrar-me"');

        $products = array_values(array_filter(array_map(
            ProductInfo::create(...),
            $this->repos->products->fetch(new ProductCriteria()),
        )));
        self::assertSame([0, 1, 2, 3], array_map(static fn (ProductInfo $p) => $p->id, $products));
        $service = new ProductService();
        foreach ($products as $product) {
            self::assertNotSame('', trim($product->name));
            self::assertStringEndsWith('.png', $product->image);
            self::assertGreaterThanOrEqual(0.0, $product->price);
            self::assertNotSame('', trim($product->description));
            self::assertEquals($product, $service->loadProductById($product->id), 'mesmo produto pelos dois caminhos');
        }

        $purchasesService = new PurchasesPanelService();
        $purchases = $purchasesService->loadPurchases(new PurchaseCriteria()->withOrderBy(PurchaseOrderBy::OLDEST_FIRST));
        self::assertSame([Seed::ADMIN_FIRST_PURCHASE_ID, Seed::ADMIN_SECOND_PURCHASE_ID], array_map(static fn ($p) => $p->id, $purchases));
        self::assertCount(1, $purchases[0]->items);
        self::assertCount(2, $purchases[1]->items);

        $purchase = new Purchase()
            ->withUser(new User()->withId($result->subject->id))
            ->withBuyDate(new DateTimeImmutable())
            ->withItems([
                new PurchaseItem()->withProduct(new Product()->withId(Seed::PEN_DRIVE2GB_ID))->withPrice(55.0)->withAmount(1),
                new PurchaseItem()->withProduct(new Product()->withId(Seed::FITA_VEDA_ROSCA_ID))->withPrice(5.0)->withAmount(2),
            ]);
        $this->repos->purchases->insert($purchase);
        self::assertSame(Seed::ADMIN_SECOND_PURCHASE_ID + 1, $purchase->id());

        $statement = $purchasesService->loadPurchasesOfUser($result->subject->id);
        self::assertCount(3, $statement);
        self::assertSame($purchase->id(), $statement[0]->id);
        self::assertCount(2, $statement[0]->items);
        self::assertSame(65.0, $statement[0]->total);

        $receipt = new ReceiptService()->loadReceipt($purchase->id());
        self::assertNotNull($receipt);
        self::assertSame(65.0, $receipt->total);
        self::assertSame(
            [['Pen Drive 2GB', 55.0, 1], ['Fita veda rosca', 5.0, 2]],
            array_map(static fn ($i) => [$i->description, $i->value, $i->quantity], $receipt->items),
        );
    }
}
