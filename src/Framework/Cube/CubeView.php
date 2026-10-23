<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/** What the presenter sees of its view. */
interface CubeView
{
    public function instanceId(): string;

    /** Signals that the ViewState changed and the view needs to be redrawn. */
    public function update(): void;

    public function release(): void;
}
