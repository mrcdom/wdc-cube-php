<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Codec;

use stdClass;
use Wdc\Framework\Commons\Serialization\EntityGraph;

/**
 * JSON codec for an entity and its criteria, using the protocol's field names.
 *
 * Only the operations the REST server uses live here; the client-side ones
 * (`writeEntityProjected`, `computeProjection`...) will come when there is a PHP client.
 *
 * @template E of object
 * @template C of object
 */
interface ModelCodec
{
    /**
     * Null fields are not written. An instance already seen in the graph is written with its id only.
     *
     * @param E $entity
     */
    public function writeEntity(object $entity, ?EntityGraph $graph = null): stdClass;

    /** @return E */
    public function readEntity(mixed $in): object;

    /** @return UpdateData<E> */
    public function readEntityForUpdate(mixed $in): UpdateData;

    /**
     * @param C $criteria
     * @return array<string, mixed>
     */
    public function writeCriteriaFields(object $criteria): array;

    /**
     * Reads criteria field $name, if it is one; false = not a criteria field.
     *
     * @param C $criteria
     */
    public function readCriteriaField(mixed $in, string $name, object $criteria): bool;
}
