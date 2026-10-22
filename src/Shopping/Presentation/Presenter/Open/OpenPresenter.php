<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open;

use Wdc\Shopping\Presentation\Presenter\Open\Login\LoginKeys;
use Wdc\Shopping\Presentation\Presenter\Restricted\RestrictedKeys;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePresenter;
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
            (new RestrictedKeys($this->app, $intent))->go();

            return false;
        }
        if ($deepest) {   // é só uma guarda: não tem tela própria
            (new LoginKeys($this->app, $intent))->go();

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
