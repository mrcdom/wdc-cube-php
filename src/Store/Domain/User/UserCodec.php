<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\User;

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
 * User in JSON: `id`, `userName`, `name`, `password`, `roles`. The REST API clears the password before
 * writing — the codec writes whatever the entity holds.
 *
 * @implements ModelCodec<User, UserCriteria>
 */
final class UserCodec implements ModelCodec
{
    public function writeEntity(object $entity, ?EntityGraph $graph = null): stdClass
    {
        $out = new stdClass();
        if ($entity->id() !== null) {
            $out->id = $entity->id();
        }
        if ($graph !== null && !$graph->track($entity)) {
            return $out;
        }
        if ($entity->userName() !== null) {
            $out->userName = $entity->userName();
        }
        if ($entity->name() !== null) {
            $out->name = $entity->name();
        }
        if ($entity->password() !== null) {
            $out->password = $entity->password();
        }
        if ($entity->roles() !== null) {
            $out->roles = $entity->roles();
        }

        return $out;
    }

    public function readEntity(mixed $in): User
    {
        $user = new User();
        foreach (Json::fields($in, 'user') as $name => $value) {
            match ($name) {
                'id' => $user->withId(InputCoerce::asInt($value)),
                'userName' => $user->withUserName(InputCoerce::asString($value)),
                'name' => $user->withName(InputCoerce::asString($value)),
                'password' => $user->withPassword(InputCoerce::asString($value)),
                'roles' => $user->withRoles(InputCoerce::asString($value)),
                default => null,
            };
        }

        return $user;
    }

    public function readEntityForUpdate(mixed $in): UpdateData
    {
        $entity = new User();
        $projection = new User();
        foreach (Json::fields($in, 'user') as $name => $value) {
            match ($name) {
                'id' => [$entity->withId(InputCoerce::asInt($value)), $projection->withId(PV::INT)],
                'userName' => [$entity->withUserName(InputCoerce::asString($value)), $projection->withUserName(PV::STR)],
                'name' => [$entity->withName(InputCoerce::asString($value)), $projection->withName(PV::STR)],
                'password' => [$entity->withPassword(InputCoerce::asString($value)), $projection->withPassword(PV::STR)],
                'roles' => [$entity->withRoles(InputCoerce::asString($value)), $projection->withRoles(PV::STR)],
                default => null,
            };
        }

        return new UpdateData($entity, $projection);
    }

    public function writeCriteriaFields(object $criteria): array
    {
        $out = [];
        CriterionCodec::write($out, 'userId', $criteria->userId(), CriterionValue::INT);
        CriterionCodec::write($out, 'userName', $criteria->userName(), CriterionValue::STRING);
        CriterionCodec::write($out, 'name', $criteria->name(), CriterionValue::STRING);
        CriterionCodec::write($out, 'roles', $criteria->roles(), CriterionValue::STRING);
        if ($criteria->orderBy() !== null) {
            $out['orderBy'] = $criteria->orderBy()->name;
        }

        return $out;
    }

    public function readCriteriaField(mixed $in, string $name, object $criteria): bool
    {
        switch ($name) {
            case 'userId':
                CriterionCodec::read($in, $criteria->userId(), CriterionValue::INT);
                break;
            case 'userName':
                CriterionCodec::read($in, $criteria->userName(), CriterionValue::STRING);
                break;
            case 'name':
                CriterionCodec::read($in, $criteria->name(), CriterionValue::STRING);
                break;
            case 'roles':
                CriterionCodec::read($in, $criteria->roles(), CriterionValue::STRING);
                break;
            case 'orderBy':
                $orderBy = CriterionCodec::readOrderBy($in, UserOrderBy::cases());
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
