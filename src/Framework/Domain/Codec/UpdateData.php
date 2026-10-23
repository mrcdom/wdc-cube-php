<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Codec;

/**
 * What an update carries: the entity and the projection of the fields present in the body — only
 * those are written.
 *
 * @template E of object
 */
final readonly class UpdateData
{
    /**
     * @param E $entity
     * @param E $projection
     */
    public function __construct(
        public object $entity,
        public object $projection,
    ) {
    }
}
