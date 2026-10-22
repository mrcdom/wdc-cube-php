<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\PurchaseItem;

use stdClass;
use Wdc\Framework\Commons\Serialization\EntityGraph;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Codec\ModelCodec;
use Wdc\Framework\Domain\Codec\UpdateData;
use Wdc\Framework\Domain\Criteria\CriterionCodec;
use Wdc\Framework\Domain\Criteria\CriterionValue;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCodec;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCodec;

/**
 * PurchaseItem no JSON: `id`, `amount`, `price`, `product`, `purchaseId`. A compra vai só
 * pelo id (`purchaseId`); na leitura aceita também o objeto `purchase`.
 *
 * @implements ModelCodec<PurchaseItem, PurchaseItemCriteria>
 */
final class PurchaseItemCodec implements ModelCodec
{
    public function writeEntity(object $entity, ?EntityGraph $graph = null): stdClass
    {
        $graph ??= new EntityGraph();
        $out = new stdClass();
        if ($entity->id() !== null) {
            $out->id = $entity->id();
        }
        if (!$graph->track($entity)) {
            return $out;
        }
        if ($entity->amount() !== null) {
            $out->amount = $entity->amount();
        }
        if ($entity->price() !== null) {
            $out->price = $entity->price();
        }
        if ($entity->product() !== null) {
            $out->product = (new ProductCodec())->writeEntity($entity->product(), $graph);
        }
        if ($entity->purchase()?->id() !== null) {
            $out->purchaseId = $entity->purchase()->id();
        }

        return $out;
    }

    public function readEntity(mixed $in): PurchaseItem
    {
        $item = new PurchaseItem();
        foreach (Json::fields($in, 'purchase-item') as $name => $value) {
            switch ($name) {
                case 'id':
                    $item->withId(InputCoerce::asInt($value));
                    break;
                case 'amount':
                    $item->withAmount(InputCoerce::asInt($value));
                    break;
                case 'price':
                    $item->withPrice(InputCoerce::asFloat($value));
                    break;
                case 'product':
                    if ($value !== null) {
                        $item->withProduct((new ProductCodec())->readEntity($value));
                    }
                    break;
                case 'purchase':
                    if ($value !== null) {
                        $item->withPurchase((new PurchaseCodec())->readEntity($value));
                    }
                    break;
                case 'purchaseId':
                    $purchaseId = InputCoerce::asInt($value);
                    if ($purchaseId !== null && $item->purchase() === null) {
                        $item->withPurchase((new Purchase())->withId($purchaseId));
                    }
                    break;
            }
        }

        return $item;
    }

    public function readEntityForUpdate(mixed $in): UpdateData
    {
        $entity = new PurchaseItem();
        $projection = new PurchaseItem();
        foreach (Json::fields($in, 'purchase-item') as $name => $value) {
            switch ($name) {
                case 'id':
                    $entity->withId(InputCoerce::asInt($value));
                    $projection->withId(PV::INT);
                    break;
                case 'amount':
                    $entity->withAmount(InputCoerce::asInt($value));
                    $projection->withAmount(PV::INT);
                    break;
                case 'price':
                    $entity->withPrice(InputCoerce::asFloat($value));
                    $projection->withPrice(PV::FLOAT);
                    break;
                case 'product':
                    if ($value !== null) {
                        $entity->withProduct((new ProductCodec())->readEntity($value));
                    }
                    $projection->withProduct((new Product())->withId(PV::INT));
                    break;
                case 'purchase':
                    if ($value !== null) {
                        $entity->withPurchase((new PurchaseCodec())->readEntity($value));
                    }
                    $projection->withPurchase((new Purchase())->withId(PV::INT));
                    break;
                case 'purchaseId':
                    $purchaseId = InputCoerce::asInt($value);
                    if ($purchaseId !== null && $entity->purchase() === null) {
                        $entity->withPurchase((new Purchase())->withId($purchaseId));
                    }
                    $projection->withPurchase((new Purchase())->withId(PV::INT));
                    break;
            }
        }

        return new UpdateData($entity, $projection);
    }

    public function writeCriteriaFields(object $criteria): array
    {
        $out = [];
        CriterionCodec::write($out, 'purchaseItemId', $criteria->purchaseItemId(), CriterionValue::INT);
        CriterionCodec::write($out, 'purchaseId', $criteria->purchaseId(), CriterionValue::INT);
        CriterionCodec::write($out, 'productId', $criteria->productId(), CriterionValue::INT);
        CriterionCodec::write($out, 'amount', $criteria->amount(), CriterionValue::INT);
        CriterionCodec::write($out, 'price', $criteria->price(), CriterionValue::FLOAT);
        CriterionCodec::write($out, 'userId', $criteria->userId(), CriterionValue::INT);
        if ($criteria->orderBy() !== null) {
            $out['orderBy'] = $criteria->orderBy()->name;
        }

        return $out;
    }

    public function readCriteriaField(mixed $in, string $name, object $criteria): bool
    {
        switch ($name) {
            case 'purchaseItemId':
                CriterionCodec::read($in, $criteria->purchaseItemId(), CriterionValue::INT);
                break;
            case 'purchaseId':
                CriterionCodec::read($in, $criteria->purchaseId(), CriterionValue::INT);
                break;
            case 'productId':
                CriterionCodec::read($in, $criteria->productId(), CriterionValue::INT);
                break;
            case 'amount':
                CriterionCodec::read($in, $criteria->amount(), CriterionValue::INT);
                break;
            case 'price':
                CriterionCodec::read($in, $criteria->price(), CriterionValue::FLOAT);
                break;
            case 'userId':
                CriterionCodec::read($in, $criteria->userId(), CriterionValue::INT);
                break;
            case 'orderBy':
                $orderBy = CriterionCodec::readOrderBy($in, PurchaseItemOrderBy::cases());
                if ($orderBy !== null) {
                    $criteria->withOrderBy($orderBy);
                }
                break;
            default:
                return false;
        }

        return true;
    }

    /** Lê um critério de item inteiro (objeto) — usado no `where` da coleção projetada. */
    public function readCriteria(mixed $in): PurchaseItemCriteria
    {
        $criteria = new PurchaseItemCriteria();
        foreach (Json::fields($in, 'critério') as $name => $value) {
            $this->readCriteriaField($value, $name, $criteria);
        }

        return $criteria;
    }
}
