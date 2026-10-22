<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/** O que o presenter enxerga da sua view. */
interface CubeView
{
    public function instanceId(): string;

    /** Avisa que o ViewState mudou e a view precisa ser redesenhada. */
    public function update(): void;

    public function release(): void;
}
