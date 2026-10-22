<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube\Fixture;

use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeView;

/**
 * Presenter que anota cada chamada no log da app. Lê/publica um parâmetro com o próprio
 * nome do place (ex.: "page=7").
 *
 * @extends AbstractCubePresenter<TestApp>
 */
final class RecordingPresenter extends AbstractCubePresenter
{
    public ?string $value = null;

    /** Para o teste de slot: a view que um filho encaixou aqui. */
    public ?CubeView $content = null;

    public function __construct(TestApp $app, public readonly TestPlace $place)
    {
        parent::__construct($app);
        $app->log[] = "create {$place->name()}";
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $this->app->log[] = sprintf('apply %s%s%s', $this->place->name(), $initialization ? ' init' : '', $deepest ? ' deepest' : '');
        $this->value = $intent->getParameterAsString($this->place->name(), $this->value);

        $behavior = $this->app->behaviors[$this->place->id()] ?? null;

        return $behavior === null ? true : $behavior($this, $intent, $initialization, $deepest);
    }

    public function publishParameters(CubeIntent $intent): void
    {
        $intent->setParameter($this->place->name(), $this->value);
    }

    public function release(): void
    {
        $this->app->log[] = "release {$this->place->name()}";
        parent::release();
    }

    public function attachView(CubeView $view): void
    {
        $this->view = $view;
    }
}
