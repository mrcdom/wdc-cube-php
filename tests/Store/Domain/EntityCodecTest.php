<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Projection\ProjectionList;
use Wdc\Store\Domain\Product\Product;
use Wdc\Store\Domain\Product\ProductCodec;
use Wdc\Store\Domain\Purchase\Purchase;
use Wdc\Store\Domain\Purchase\PurchaseCodec;
use Wdc\Store\Domain\PurchaseItem\PurchaseItem;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemCodec;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemOrderBy;
use Wdc\Store\Domain\User\User;
use Wdc\Store\Domain\User\UserCodec;

/** The entities' JSON, field by field — the wire format REST clients expect. */
final class EntityCodecTest extends TestCase
{
    #[Test]
    public function product_writesNullFieldsAsAbsent_andNeverTheImage(): void
    {
        $product = (new Product())->withId(3)->withName('Ball')->withPrice(10.0)->withImage('PNG');

        self::assertSame('{"id":3,"name":"Ball","price":10.0}', Json::encode((new ProductCodec())->writeEntity($product)));
    }

    #[Test]
    public function user_fields(): void
    {
        $user = (new User())->withId(1)->withUserName('john')->withName('John')->withRoles('CUSTOMER');

        self::assertSame('{"id":1,"userName":"john","name":"John","roles":"CUSTOMER"}', Json::encode((new UserCodec())->writeEntity($user)));
    }

    #[Test]
    public function purchase_withItems_itemsDoNotRepeatThePurchase(): void
    {
        $purchase = (new Purchase())->withId(7)->withBuyDate(new DateTimeImmutable('2024-01-15T10:30:00.5Z'))
            ->withUser((new User())->withId(1)->withName('John'));
        $item = (new PurchaseItem())->withId(70)->withAmount(2)->withPrice(5.5)
            ->withProduct((new Product())->withId(3)->withName('Ball'))->withPurchase($purchase);
        $purchase->withItems([$item]);

        self::assertSame(
            '{"id":7,"buyDate":"2024-01-15T10:30:00.5Z","user":{"id":1,"name":"John"},'
            . '"items":[{"id":70,"amount":2,"price":5.5,"product":{"id":3,"name":"Ball"}}]}',
            Json::encode((new PurchaseCodec())->writeEntity($purchase)),
        );
    }

    #[Test]
    public function purchaseItem_alone_carriesThePurchaseId(): void
    {
        $item = (new PurchaseItem())->withId(70)->withAmount(1)->withPurchase((new Purchase())->withId(7));

        self::assertSame('{"id":70,"amount":1,"purchaseId":7}', Json::encode((new PurchaseItemCodec())->writeEntity($item)));
    }

    #[Test]
    public function sameInstanceTwice_isWrittenAsIdOnly(): void
    {
        $ball = (new Product())->withId(3)->withName('Ball');
        $purchase = (new Purchase())->withId(7)->withItems([
            (new PurchaseItem())->withId(70)->withProduct($ball),
            (new PurchaseItem())->withId(71)->withProduct($ball),
            (new PurchaseItem())->withId(72)->withProduct((new Product())->withId(3)->withName('Ball')),
        ]);

        $items = Json::encode((new PurchaseCodec())->writeEntity($purchase)->items);

        self::assertSame(
            '[{"id":70,"product":{"id":3,"name":"Ball"}},{"id":71,"product":{"id":3}},{"id":72,"product":{"id":3,"name":"Ball"}}]',
            $items,
            'the same instance becomes a stub; another instance with the same id does not',
        );
    }

    #[Test]
    public function projectedCollection_travelsAsEnvelope_andComesBack(): void
    {
        $where = (new PurchaseItemCriteria())->withOrderBy(PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST);
        $where->amount()->ge(2);
        $projection = (new Purchase())->withId(1)->withItems(
            (new ProjectionList((new PurchaseItem())->withId(1)->withPrice(1.0), $where))->withLimit(5)->withOffset(0),
        );
        $codec = new PurchaseCodec();

        $json = Json::encode($codec->writeEntity($projection));
        self::assertSame(
            '{"id":1,"items":{"shape":{"id":1,"price":1.0},"where":{"amount":{"p":[{"o":"GE","v":[2]}]},"orderBy":"MOST_EXPENSIVE_FIRST"},"limit":5,"offset":0}}',
            $json,
        );

        $items = $codec->readEntity(Json::decode($json))->items();
        self::assertInstanceOf(ProjectionList::class, $items);
        self::assertSame(5, $items->limit());
        self::assertSame(0, $items->offset());
        self::assertSame(1.0, $items->shape->price());
        self::assertInstanceOf(PurchaseItemCriteria::class, $items->criteria);
        self::assertSame(PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST, $items->criteria->orderBy());
        self::assertSame([2], $items->criteria->amount()->predicates()[0]->values);
    }

    #[Test]
    public function readingItems_givesThemAStubOfThePurchase(): void
    {
        $purchase = (new PurchaseCodec())->readEntity(Json::decode('{"id": 7, "items": [{"id": 70, "product": {"id": 3}}]}'));
        $items = $purchase->items();

        self::assertIsArray($items);
        self::assertSame(7, $items[0]->purchaseId());
        self::assertNotSame($purchase, $items[0]->purchase(), 'a stub, not the purchase itself (no cycle)');
        self::assertSame(3, $items[0]->productId());
    }

    #[Test]
    public function readForUpdate_projectsOnlyTheFieldsPresent(): void
    {
        $data = (new ProductCodec())->readEntityForUpdate(Json::decode('{"id": 3, "price": "12.5"}'));

        self::assertSame(12.5, $data->entity->price());
        self::assertNotNull($data->projection->price());
        self::assertNull($data->projection->name(), 'name was not sent: it is not written');
    }

    #[Test]
    public function purchaseItem_acceptsPurchaseIdOrPurchaseObject(): void
    {
        $codec = new PurchaseItemCodec();
        self::assertSame(7, $codec->readEntity(Json::decode('{"purchaseId": 7}'))->purchaseId());
        self::assertSame(8, $codec->readEntity(Json::decode('{"purchase": {"id": 8}, "purchaseId": 7}'))->purchaseId());
    }
}
