<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Node of the navigation tree. In the application it is usually an `enum Place: int implements CubePlace`.
 *
 * Invariant: the id grows with depth — release goes
 * from leaf to root in descending id order.
 */
interface CubePlace
{
    public function id(): int;

    public function name(): string;

    public function createPresenter(CubeApplication $app): CubePresenter;
}
