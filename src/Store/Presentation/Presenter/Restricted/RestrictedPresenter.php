<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted;

use Wdc\Store\Presentation\Presenter\Restricted\Home\HomeKeys;
use Wdc\Store\Presentation\Presenter\Open\OpenKeys;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePresenter;
use Wdc\Store\Presentation\StoreApplication;

/** Guard of the restricted area (no view). With no logged-in user, goes back to the public area. */
final class RestrictedPresenter implements CubePresenter
{
    public function __construct(public readonly StoreApplication $app)
    {
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        if ($this->app->subject() === null) {
            (new OpenKeys($this->app, $intent))->go();

            return false;
        }
        if ($deepest) {   // it is just a guard: the restricted area's default screen is the home
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
