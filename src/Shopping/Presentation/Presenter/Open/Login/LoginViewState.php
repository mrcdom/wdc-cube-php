<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

use Wdc\Framework\Cube\ViewState;

final class LoginViewState implements ViewState
{
    public ?string $userName = null;

    public int $errorCode = 0;

    public ?string $errorMessage = null;

    public bool $loading = false;
}
