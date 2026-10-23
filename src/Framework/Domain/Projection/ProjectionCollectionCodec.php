<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Projection;

use Closure;
use stdClass;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * How a 1:N **projected collection** travels over the wire. It is not a list of results: it is an
 * item shape plus what to do with the collection — the child criteria and the per-parent slice.
 *
 * The wire shape alone tells projection from result: the projection is an object, the
 * result the usual array.
 *
 *     "items": { "shape": {…one item…}, "where": {…criteria…}, "limit": 5, "offset": 0 }   // projection
 *     "items": [ {…}, {…} ]                                                              // result
 */
final class ProjectionCollectionCodec
{
    private function __construct()
    {
    }

    /**
     * @template E of object
     * @param ProjectionList<E> $collection
     * @param Closure(E): mixed $shapeWriter
     * @param (Closure(object): mixed)|null $criteriaWriter writes the whole embedded criteria
     */
    public static function write(ProjectionList $collection, Closure $shapeWriter, ?Closure $criteriaWriter): stdClass
    {
        $envelope = new stdClass();
        $envelope->shape = $shapeWriter($collection->shape);
        if ($collection->criteria !== null && $criteriaWriter !== null) {
            $envelope->where = $criteriaWriter($collection->criteria);
        }
        if ($collection->limit() !== null) {
            $envelope->limit = $collection->limit();
        }
        if ($collection->offset() !== null) {
            $envelope->offset = $collection->offset();
        }

        return $envelope;
    }

    /** Whether the field value is a projected collection (object) rather than a list of results (array). */
    public static function isProjectionEnvelope(mixed $in): bool
    {
        return $in instanceof stdClass;
    }

    /**
     * @template E of object
     * @param Closure(mixed): E $shapeReader
     * @param Closure(mixed): object $criteriaReader
     * @return ProjectionList<E>
     */
    public static function read(mixed $in, Closure $shapeReader, Closure $criteriaReader): ProjectionList
    {
        $shape = null;
        $criteria = null;
        $limit = null;
        $offset = null;
        foreach (Json::fields($in, 'projected collection') as $name => $value) {
            match ($name) {
                'shape' => $shape = $shapeReader($value),
                'where' => $criteria = $criteriaReader($value),
                'limit' => $limit = InputCoerce::asInt($value),
                'offset' => $offset = InputCoerce::asInt($value),
                default => null,
            };
        }
        if ($shape === null) {
            throw new InvalidRequestException('Projected collection without "shape"');
        }

        return (new ProjectionList($shape, $criteria))->withLimit($limit)->withOffset($offset);
    }
}
