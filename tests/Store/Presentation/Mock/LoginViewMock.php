<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Presentation\Mock;

use Wdc\Store\Presentation\Presenter\Open\Login\LoginPresenter;
use Wdc\Store\Presentation\Presenter\Open\Login\LoginViewState;

final class LoginViewMock extends AbstractViewMock
{
    public readonly LoginViewState $state;

    public function __construct(public readonly LoginPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
