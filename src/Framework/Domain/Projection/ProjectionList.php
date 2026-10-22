<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Projection;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Projeção de uma coleção 1:N: a forma do item ($shape) + critério do filho (filtro e
 * ordem) + recorte opcional (limit/offset), aplicado POR PAI.
 *
 * Um array PHP não carrega metadados, então aqui é uma classe que se comporta como coleção (iterável e contável).
 *
 * @template E of object
 * @implements IteratorAggregate<int, E>
 */
final class ProjectionList implements IteratorAggregate, Countable
{
    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * @param E $shape
     */
    public function __construct(
        public readonly object $shape,
        public readonly ?object $criteria = null,
    ) {
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function withLimit(?int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(): ?int
    {
        return $this->offset;
    }

    public function withOffset(?int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    /** @return Traversable<int, E> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator([$this->shape]);
    }

    public function count(): int
    {
        return 1;
    }
}
