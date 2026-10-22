<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Codec;

/**
 * O que um update traz: a entidade e a projeção dos campos presentes no corpo — só eles
 * são gravados.
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
