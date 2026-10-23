<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use Closure;

/**
 * A spot where a child presenter plugs in its view, offered by the parent.
 *
 * In PHP a Closure cannot implement interfaces, so the slot
 * is a small object wrapping the closure: `new CubeViewSlot($this->setContentView(...))`.
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
