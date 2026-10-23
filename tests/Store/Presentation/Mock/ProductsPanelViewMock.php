<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Presentation\Mock;

use Wdc\Store\Presentation\Presenter\Restricted\Home\Products\ProductsPanelPresenter;
use Wdc\Store\Presentation\Presenter\Restricted\Home\Products\ProductsPanelViewState;

final class ProductsPanelViewMock extends AbstractViewMock
{
    public readonly ProductsPanelViewState $state;

    public function __construct(public readonly ProductsPanelPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
