<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Presentation\Mock;

use Wdc\Store\Presentation\Presenter\RootPresenter;
use Wdc\Store\Presentation\Presenter\RootViewState;

final class RootViewMock extends AbstractViewMock
{
    public readonly RootViewState $state;

    public function __construct(public readonly RootPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
