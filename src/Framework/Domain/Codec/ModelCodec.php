<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Codec;

use stdClass;
use Wdc\Framework\Commons\Serialization\EntityGraph;

/**
 * Codec JSON de uma entidade e do seu critério, com os nomes de campo do protocolo.
 *
 * Aqui ficam só as operações que o servidor REST usa; as do lado cliente
 * (`writeEntityProjected`, `computeProjection`...) ficam para quando houver um cliente PHP.
 *
 * @template E of object
 * @template C of object
 */
interface ModelCodec
{
    /**
     * Campo null não é escrito. Uma instância já vista no grafo sai só com o id.
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
     * Lê o campo de critério $name, se for um; false = não é campo de critério.
     *
     * @param C $criteria
     */
    public function readCriteriaField(mixed $in, string $name, object $criteria): bool;
}
