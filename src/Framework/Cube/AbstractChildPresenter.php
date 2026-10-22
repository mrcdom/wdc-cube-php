<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Base dos presenters-filhos (painéis): não ocupam place; o pai os cria, guarda a view
 * deles no próprio ViewState e os libera.
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

    /** Cria a view, inicializa e devolve a view (para o pai guardar no seu estado). */
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
