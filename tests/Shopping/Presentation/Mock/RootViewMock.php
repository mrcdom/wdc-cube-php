<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Shopping\Presentation\Presenter\RootPresenter;
use Wdc\Shopping\Presentation\Presenter\RootViewState;

final class RootViewMock extends AbstractViewMock
{
    public readonly RootViewState $state;

    public function __construct(public readonly RootPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
