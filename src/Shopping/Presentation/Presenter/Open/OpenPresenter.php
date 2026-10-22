<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open;

use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePresenter;
use Wdc\Shopping\Presentation\Routes;
use Wdc\Shopping\Presentation\ShoppingApplication;

/** Guarda da área pública (sem view). Quem já está logado vai para a área restrita. */
final class OpenPresenter implements CubePresenter
{
    public function __construct(public readonly ShoppingApplication $app)
    {
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        if ($this->app->subject() !== null) {
            Routes::restricted($this->app, $intent);

            return false;
        }
        if ($deepest) {   // é só uma guarda: não tem tela própria
            Routes::login($this->app, $intent);

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
