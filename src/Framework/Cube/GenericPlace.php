<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use LogicException;

/** A place known only by name — what `CubeIntent::parse()` produces. */
final readonly class GenericPlace implements CubePlace
{
    public function __construct(private string $name, private int $id = -1)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createPresenter(CubeApplication $app): CubePresenter
    {
        throw new LogicException("GenericPlace '{$this->name}' does not create a presenter");
    }
}
