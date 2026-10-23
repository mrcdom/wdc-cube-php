<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/** Navigable presenter: occupies a place in the active chain. */
interface CubePresenter extends PresenterBase
{
    /**
     * @param bool $initialization true when the presenter has just been created
     * @param bool $deepest        true only for the target place (the leaf of the chain)
     * @return bool false stops the navigation (guard, redirect)
     */
    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool;

    /** Writes into the intent the parameters that represent the current state (they become the URL). */
    public function publishParameters(CubeIntent $intent): void;
}
