<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Product;

use stdClass;
use Wdc\Framework\Commons\Serialization\EntityGraph;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Codec\ModelCodec;
use Wdc\Framework\Domain\Codec\UpdateData;
use Wdc\Framework\Domain\Criteria\CriterionCodec;
use Wdc\Framework\Domain\Criteria\CriterionValue;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;

/**
 * Product in JSON: `id`, `name`, `price`, `description`. The image never goes in the JSON — it has
 * its own route (`/api/repo/product/{id}/image`).
 *
 * @implements ModelCodec<Product, ProductCriteria>
 */
final class ProductCodec implements ModelCodec
{
    public function writeEntity(object $entity, ?EntityGraph $graph = null): stdClass
    {
        $out = new stdClass();
        if ($graph !== null && !$graph->track($entity)) {
            if ($entity->id() !== null) {
                $out->id = $entity->id();
            }

            return $out;
        }
        if ($entity->id() !== null) {
            $out->id = $entity->id();
        }
        if ($entity->name() !== null) {
            $out->name = $entity->name();
        }
        if ($entity->price() !== null) {
            $out->price = $entity->price();
        }
        if ($entity->description() !== null) {
            $out->description = $entity->description();
        }

        return $out;
    }

    public function readEntity(mixed $in): Product
    {
        $product = new Product();
        foreach (Json::fields($in, 'product') as $name => $value) {
            match ($name) {
                'id' => $product->withId(InputCoerce::asInt($value)),
                'name' => $product->withName(InputCoerce::asString($value)),
                'price' => $product->withPrice(InputCoerce::asFloat($value)),
                'description' => $product->withDescription(InputCoerce::asString($value)),
                default => null,
            };
        }

        return $product;
    }

    public function readEntityForUpdate(mixed $in): UpdateData
    {
        $entity = new Product();
        $projection = new Product();
        foreach (Json::fields($in, 'product') as $name => $value) {
            match ($name) {
                'id' => [$entity->withId(InputCoerce::asInt($value)), $projection->withId(PV::INT)],
                'name' => [$entity->withName(InputCoerce::asString($value)), $projection->withName(PV::STR)],
                'price' => [$entity->withPrice(InputCoerce::asFloat($value)), $projection->withPrice(PV::FLOAT)],
                'description' => [$entity->withDescription(InputCoerce::asString($value)), $projection->withDescription(PV::STR)],
                default => null,
            };
        }

        return new UpdateData($entity, $projection);
    }

    public function writeCriteriaFields(object $criteria): array
    {
        $out = [];
        CriterionCodec::write($out, 'productId', $criteria->productId(), CriterionValue::INT);
        CriterionCodec::write($out, 'name', $criteria->name(), CriterionValue::STRING);
        CriterionCodec::write($out, 'price', $criteria->price(), CriterionValue::FLOAT);
        CriterionCodec::write($out, 'description', $criteria->description(), CriterionValue::STRING);
        if ($criteria->orderBy() !== null) {
            $out['orderBy'] = $criteria->orderBy()->name;
        }

        return $out;
    }

    public function readCriteriaField(mixed $in, string $name, object $criteria): bool
    {
        switch ($name) {
            case 'productId':
                CriterionCodec::read($in, $criteria->productId(), CriterionValue::INT);
                break;
            case 'name':
                CriterionCodec::read($in, $criteria->name(), CriterionValue::STRING);
                break;
            case 'price':
                CriterionCodec::read($in, $criteria->price(), CriterionValue::FLOAT);
                break;
            case 'description':
                CriterionCodec::read($in, $criteria->description(), CriterionValue::STRING);
                break;
            case 'orderBy':
                $orderBy = CriterionCodec::readOrderBy($in, ProductOrderBy::cases());
                if ($orderBy !== null) {
                    $criteria->withOrderBy($orderBy);
                }
                break;
            default:
                return false;
        }

        return true;
    }
}
