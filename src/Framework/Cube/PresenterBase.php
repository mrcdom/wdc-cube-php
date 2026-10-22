<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Base comum de todo presenter. Interfaces PHP não têm implementação padrão, então as
 * classes abstratas fornecem a versão NOOP dos dois métodos.
 */
interface PresenterBase
{
    /** Recalcula estado derivado logo antes de a view ser serializada. */
    public function commitComputedState(): void;

    public function release(): void;
}
