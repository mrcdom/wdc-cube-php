<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted;

use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomeKeys;
use Wdc\Shopping\Presentation\Presenter\Open\OpenKeys;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePresenter;
use Wdc\Shopping\Presentation\ShoppingApplication;

/** Guarda da área restrita (sem view). Sem usuário logado, volta para a área pública. */
final class RestrictedPresenter implements CubePresenter
{
    public function __construct(public readonly ShoppingApplication $app)
    {
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        if ($this->app->subject() === null) {
            (new OpenKeys($this->app, $intent))->go();

            return false;
        }
        if ($deepest) {   // é só uma guarda: a tela padrão da área restrita é a home
            (new HomeKeys($this->app, $intent))->go();

            return false;
        }

        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
    }

    public function commitComputedState(): void
    {
    }

    public function release(): void
    {
    }
}
