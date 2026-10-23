<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Criteria\Operator;
use Wdc\Framework\Domain\Exception\InvalidRequestException;
use Wdc\Store\Domain\Product\ProductCodec;
use Wdc\Store\Domain\Product\ProductCriteria;
use Wdc\Store\Domain\Product\ProductOrderBy;
use Wdc\Store\Domain\Purchase\PurchaseCodec;
use Wdc\Store\Domain\Purchase\PurchaseCriteria;

final class CriterionCodecTest extends TestCase
{
    private function readProduct(string $json): ProductCriteria
    {
        $codec = new ProductCodec();
        $criteria = new ProductCriteria();
        foreach (Json::fields(Json::decode($json)) as $name => $value) {
            $codec->readCriteriaField($value, $name, $criteria);
        }

        return $criteria;
    }

    #[Test]
    public function writes_theExpressiveShape(): void
    {
        $criteria = (new ProductCriteria())->withOrderBy(ProductOrderBy::CHEAPEST_FIRST);
        $criteria->price()->or()->ge(10.0)->price()->isNull();
        $criteria->name()->like('Wilson%');

        self::assertSame(
            '{"name":{"p":[{"o":"LIKE","v":["Wilson%"]}]},"price":{"or":true,"p":[{"o":"GE","v":[10.0]},{"o":"IS_NULL"}]},"orderBy":"CHEAPEST_FIRST"}',
            Json::encode((object) (new ProductCodec())->writeCriteriaFields($criteria)),
        );
    }

    #[Test]
    public function roundTrip_keepsOperatorsValuesAndDisjunction(): void
    {
        $original = new ProductCriteria();
        $original->price()->between(1.5, 9.0);
        $original->productId()->or()->in([1, 2, 3])->productId()->isNull();
        $json = Json::encode((object) (new ProductCodec())->writeCriteriaFields($original));

        $read = $this->readProduct($json);

        self::assertEquals($original->price()->predicates(), $read->price()->predicates());
        self::assertEquals($original->productId()->predicates(), $read->productId()->predicates());
        self::assertTrue($read->productId()->disjunctive());
        self::assertFalse($read->price()->disjunctive());
    }

    #[Test]
    public function bareValue_readsAsEquality(): void
    {
        $criteria = $this->readProduct('{"productId": 2, "name": "Wilson"}');

        self::assertSame(Operator::EQ, $criteria->productId()->predicates()[0]->operator);
        self::assertSame([2], $criteria->productId()->predicates()[0]->values);
        self::assertSame(['Wilson'], $criteria->name()->predicates()[0]->values);
    }

    #[Test]
    public function numbersInText_areCoerced(): void
    {
        $criteria = $this->readProduct('{"productId": {"p": [{"o": "IN", "v": ["1", 2.0, null]}]}}');

        self::assertSame([1, 2, null], $criteria->productId()->predicates()[0]->values);
    }

    #[Test]
    public function unknownOperator_isDropped_notApproximated(): void
    {
        $criteria = $this->readProduct('{"price": {"p": [{"o": "ALMOST", "v": [1]}, {"o": "LT", "v": [5]}]}}');

        self::assertCount(1, $criteria->price()->predicates());
        self::assertSame(Operator::LT, $criteria->price()->predicates()[0]->operator);
    }

    #[Test]
    public function unknownOrderBy_isRefused_namingTheAcceptedOnes(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage("unknown ordering: 'BY_COLOR' — accepted: OLDEST_FIRST, NEWEST_FIRST, NAME_A_TO_Z");

        $this->readProduct('{"orderBy": "BY_COLOR"}');
    }

    #[Test]
    public function dates_travelAsIsoWithOffset(): void
    {
        $criteria = new PurchaseCriteria();
        $criteria->buyDate()->ge(new DateTimeImmutable('2024-01-15T10:30:00Z'));
        $json = Json::encode((object) (new PurchaseCodec())->writeCriteriaFields($criteria));
        self::assertSame('{"buyDate":{"p":[{"o":"GE","v":["2024-01-15T10:30:00Z"]}]}}', $json);

        $read = new PurchaseCriteria();
        (new PurchaseCodec())->readCriteriaField(Json::fields(Json::decode('{"b": {"p": [{"o": "LT", "v": ["2024-01-15T07:30:00-03:00"]}]}}'))['b'], 'buyDate', $read);
        $value = $read->buyDate()->predicates()[0]->values[0];
        self::assertInstanceOf(DateTimeImmutable::class, $value);
        self::assertEquals(new DateTimeImmutable("2024-01-15T10:30:00Z"), $value, "same instant");
    }

    #[Test]
    public function textThatIsNotADate_isRefused(): void
    {
        $this->expectException(InvalidRequestException::class);
        (new PurchaseCodec())->readCriteriaField('tomorrow', 'buyDate', new PurchaseCriteria());
    }

    #[Test]
    public function unknownField_isNotACriteriaField(): void
    {
        self::assertFalse((new ProductCodec())->readCriteriaField(1, 'color', new ProductCriteria()));
    }
}
