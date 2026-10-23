<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Base for child presenters (panels): they do not occupy a place; the parent creates them, keeps
 * their view in its own ViewState, and releases them.
 *
 * @template A of CubeApplication
 */
abstract class AbstractChildPresenter implements PresenterBase
{
    protected ?CubeView $view = null;

    /**
     * @param A $app
     */
    public function __construct(public readonly CubeApplication $app)
    {
    }

    abstract protected function onCreateView(): CubeView;

    abstract protected function onInitialize(): void;

    /** Creates the view, initializes, and returns the view (for the parent to keep in its state). */
    final public function initialize(): CubeView
    {
        $view = $this->onCreateView();
        $this->view = $view;
        $this->onInitialize();

        return $view;
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

    public function commitComputedState(): void
    {
    }
}
