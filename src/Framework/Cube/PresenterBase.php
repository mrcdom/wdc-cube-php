<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Common base of every presenter. PHP interfaces have no default implementations, so the
 * abstract classes provide the NOOP version of both methods.
 */
interface PresenterBase
{
    /** Recomputes derived state right before the view is serialized. */
    public function commitComputedState(): void;

    public function release(): void;
}
