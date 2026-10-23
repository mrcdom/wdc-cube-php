<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Base for navigable presenters.
 *
 * @template A of CubeApplication
 */
abstract class AbstractCubePresenter implements CubePresenter
{
    protected ?CubeView $view = null;

    /**
     * @param A $app
     */
    public function __construct(public readonly CubeApplication $app)
    {
    }

    public function view(): ?CubeView
    {
        return $this->view;
    }

    public function update(): void
    {
        $this->view?->update();
    }

    public function release(): void
    {
        $this->view?->release();
        $this->view = null;
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
    }

    public function commitComputedState(): void
    {
    }
}
