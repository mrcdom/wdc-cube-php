<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Purchase;

use stdClass;
use Wdc\Framework\Commons\Serialization\EntityGraph;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\IsoDateTime;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Codec\ModelCodec;
use Wdc\Framework\Domain\Codec\UpdateData;
use Wdc\Framework\Domain\Criteria\CriterionCodec;
use Wdc\Framework\Domain\Criteria\CriterionValue;
use Wdc\Framework\Domain\Projection\ProjectionCollectionCodec;
use Wdc\Framework\Domain\Projection\ProjectionList;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Store\Domain\Product\ProductCodec;
use Wdc\Store\Domain\PurchaseItem\PurchaseItem;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemCodec;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Store\Domain\User\User;
use Wdc\Store\Domain\User\UserCodec;

/**
 * Purchase in JSON: `id`, `buyDate` (ISO-8601 with offset), `user`, `items`.
 *
 * `items` works both ways: a list of results (array) or a projected collection (object
 * `{shape, where, limit, offset}`, see {@see ProjectionCollectionCodec}). Items inside a
 * purchase do not repeat the `purchaseId` — the purchase is the enclosing object.
 *
 * @implements ModelCodec<Purchase, PurchaseCriteria>
 */
final class PurchaseCodec implements ModelCodec
{
    public function writeEntity(object $entity, ?EntityGraph $graph = null): stdClass
    {
        $graph ??= new EntityGraph();
        $out = new stdClass();
        if ($entity->id() !== null) {
            $out->id = $entity->id();
        }
        if (!$graph->track($entity)) {
            return $out;   // already written in this graph: key only
        }
        if ($entity->buyDate() !== null) {
            $out->buyDate = IsoDateTime::format($entity->buyDate());
        }
        if ($entity->user() !== null) {
            $out->user = (new UserCodec())->writeEntity($entity->user(), $graph);
        }
        $items = $entity->items();
        if ($items instanceof ProjectionList) {
            $itemCodec = new PurchaseItemCodec();
            $out->items = ProjectionCollectionCodec::write(
                $items,
                static fn (PurchaseItem $item) => self::writeItem($item, $graph),
                static fn (object $criteria) => $criteria instanceof PurchaseItemCriteria
                    ? (object) $itemCodec->writeCriteriaFields($criteria)
                    : null,
            );
        } elseif ($items !== null) {
            $out->items = array_map(static fn (PurchaseItem $item) => self::writeItem($item, $graph), $items);
        }

        return $out;
    }

    public function readEntity(mixed $in): Purchase
    {
        $purchase = new Purchase();
        foreach (Json::fields($in, 'purchase') as $name => $value) {
            switch ($name) {
                case 'id':
                    $purchase->withId(InputCoerce::asInt($value));
                    break;
                case 'buyDate':
                    $text = InputCoerce::asString($value);
                    if ($text !== null) {
                        $purchase->withBuyDate(IsoDateTime::parse($text));
                    }
                    break;
                case 'user':
                    if ($value !== null) {
                        $purchase->withUser((new UserCodec())->readEntity($value));
                    }
                    break;
                case 'items':
                    if ($value === null) {
                        break;
                    }
                    if (ProjectionCollectionCodec::isProjectionEnvelope($value)) {
                        $itemCodec = new PurchaseItemCodec();
                        $purchase->withItems(ProjectionCollectionCodec::read(
                            $value,
                            static fn (mixed $i) => self::readItem($i, $purchase),
                            $itemCodec->readCriteria(...),
                        ));
                    } else {
                        $purchase->withItems(array_map(
                            static fn (mixed $i) => self::readItem($i, $purchase),
                            Json::elements($value, 'items'),
                        ));
                    }
                    break;
            }
        }

        return $purchase;
    }

    public function readEntityForUpdate(mixed $in): UpdateData
    {
        $entity = new Purchase();
        $projection = new Purchase();
        foreach (Json::fields($in, 'purchase') as $name => $value) {
            switch ($name) {
                case 'id':
                    $entity->withId(InputCoerce::asInt($value));
                    $projection->withId(PV::INT);
                    break;
                case 'buyDate':
                    $text = InputCoerce::asString($value);
                    if ($text !== null) {
                        $entity->withBuyDate(IsoDateTime::parse($text));
                    }
                    $projection->withBuyDate(PV::dateTime());
                    break;
                case 'user':
                    if ($value !== null) {
                        $entity->withUser((new UserCodec())->readEntity($value));
                    }
                    $projection->withUser((new User())->withId(PV::INT));
                    break;
            }
        }

        return new UpdateData($entity, $projection);
    }

    public function writeCriteriaFields(object $criteria): array
    {
        $out = [];
        CriterionCodec::write($out, 'purchaseId', $criteria->purchaseId(), CriterionValue::INT);
        CriterionCodec::write($out, 'userId', $criteria->userId(), CriterionValue::INT);
        CriterionCodec::write($out, 'buyDate', $criteria->buyDate(), CriterionValue::DATETIME);
        CriterionCodec::write($out, 'productId', $criteria->productId(), CriterionValue::INT);
        if ($criteria->orderBy() !== null) {
            $out['orderBy'] = $criteria->orderBy()->name;
        }

        return $out;
    }

    public function readCriteriaField(mixed $in, string $name, object $criteria): bool
    {
        switch ($name) {
            case 'purchaseId':
                CriterionCodec::read($in, $criteria->purchaseId(), CriterionValue::INT);
                break;
            case 'userId':
                CriterionCodec::read($in, $criteria->userId(), CriterionValue::INT);
                break;
            case 'buyDate':
                CriterionCodec::read($in, $criteria->buyDate(), CriterionValue::DATETIME);
                break;
            case 'productId':
                CriterionCodec::read($in, $criteria->productId(), CriterionValue::INT);
                break;
            case 'orderBy':
                $orderBy = CriterionCodec::readOrderBy($in, PurchaseOrderBy::cases());
                if ($orderBy !== null) {
                    $criteria->withOrderBy($orderBy);
                }
                break;
            default:
                return false;
        }

        return true;
    }

    /** Item inside a purchase: no `purchaseId` (the purchase is the enclosing object). */
    private static function writeItem(PurchaseItem $item, EntityGraph $graph): stdClass
    {
        $out = new stdClass();
        if ($item->id() !== null) {
            $out->id = $item->id();
        }
        if (!$graph->track($item)) {
            return $out;
        }
        if ($item->amount() !== null) {
            $out->amount = $item->amount();
        }
        if ($item->price() !== null) {
            $out->price = $item->price();
        }
        if ($item->product() !== null) {
            $out->product = (new ProductCodec())->writeEntity($item->product(), $graph);
        }

        return $out;
    }

    /** The back-reference to the purchase goes as a stub (key only), with no cycle in the graph. */
    private static function readItem(mixed $in, Purchase $parent): PurchaseItem
    {
        $item = new PurchaseItem();
        if ($parent->id() !== null) {
            $item->withPurchase((new Purchase())->withId($parent->id()));
        }
        foreach (Json::fields($in, 'items[]') as $name => $value) {
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
            }
        }

        return $item;
    }
}
