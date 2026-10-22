<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter;

use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\ViewState;

final class RootViewState implements ViewState
{
    public ?CubeView $contentView = null;

    public ?string $errorMessage = null;
}
