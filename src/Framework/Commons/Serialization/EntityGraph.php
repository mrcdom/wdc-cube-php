<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Serialization;

use SplObjectStorage;

/**
 * Entities already written during a serialization, by **instance identity** (`===`), to break
 * cycles: the same instance seen again is written with its key only. Two instances with the same
 * id are independent. One instance per top-level operation.
 */
final class EntityGraph
{
    /** @var SplObjectStorage<object, null> */
    private SplObjectStorage $seen;

    public function __construct()
    {
        $this->seen = new SplObjectStorage();
    }

    /** true the first time; false if this instance was already seen (write a key-only stub). */
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
