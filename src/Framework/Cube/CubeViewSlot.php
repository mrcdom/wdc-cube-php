<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use Closure;

/**
 * Lugar onde um presenter-filho encaixa a sua view, oferecido pelo pai.
 *
 * Em PHP uma Closure não implementa interfaces, então o slot
 * é um pequeno objeto que embrulha a closure: `new CubeViewSlot($this->setContentView(...))`.
 */
final readonly class CubeViewSlot
{
    /**
     * @param Closure(?CubeView): mixed $setter
     */
    public function __construct(private Closure $setter)
    {
    }

    public function setView(?CubeView $view): void
    {
        ($this->setter)($view);
    }
}
