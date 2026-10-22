<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use LogicException;

/** Place conhecido só pelo nome — o que sai de `CubeIntent::parse()`. */
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
        throw new LogicException("GenericPlace '{$this->name}' não cria presenter");
    }
}
