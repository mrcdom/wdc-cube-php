<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Projection;

use Closure;
use stdClass;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * Como uma **coleção projetada** 1:N trafega. Não é uma lista de resultados: é uma forma de
 * item mais o que fazer com a coleção — o critério do filho e o recorte por pai.
 *
 * A forma no fio distingue projeção de resultado sozinha: a projeção é um objeto, o
 * resultado o array de sempre.
 *
 *     "items": { "shape": {…um item…}, "where": {…critério…}, "limit": 5, "offset": 0 }   // projeção
 *     "items": [ {…}, {…} ]                                                              // resultado
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
     * @param (Closure(object): mixed)|null $criteriaWriter escreve o critério embutido inteiro
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

    /** Se o valor do campo é uma coleção projetada (objeto), e não uma lista de resultados (array). */
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
        foreach (Json::fields($in, 'coleção projetada') as $name => $value) {
            match ($name) {
                'shape' => $shape = $shapeReader($value),
                'where' => $criteria = $criteriaReader($value),
                'limit' => $limit = InputCoerce::asInt($value),
                'offset' => $offset = InputCoerce::asInt($value),
                default => null,
            };
        }
        if ($shape === null) {
            throw new InvalidRequestException('Coleção projetada sem "shape"');
        }

        return (new ProjectionList($shape, $criteria))->withLimit($limit)->withOffset($offset);
    }
}
