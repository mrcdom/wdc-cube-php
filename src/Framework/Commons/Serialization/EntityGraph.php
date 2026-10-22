<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use SplObjectStorage;

/**
 * Entidades já escritas numa serialização, por **identidade de instância** (`===`), para cortar
 * ciclos: a mesma instância vista de novo é escrita só com a chave. Duas instâncias com o mesmo
 * id são independentes. Uma instância por operação de topo.
 */
final class EntityGraph
{
    /** @var SplObjectStorage<object, null> */
    private SplObjectStorage $seen;

    public function __construct()
    {
        $this->seen = new SplObjectStorage();
    }

    /** true na 1ª vez; false se esta instância já passou (use um stub só com a chave). */
    public function track(?object $entity): bool
    {
        if ($entity === null) {
            return true;
        }
        if ($this->seen->offsetExists($entity)) {
            return false;
        }
        $this->seen->offsetSet($entity);

        return true;
    }

    public function isSeen(?object $entity): bool
    {
        return $entity !== null && $this->seen->offsetExists($entity);
    }
}
