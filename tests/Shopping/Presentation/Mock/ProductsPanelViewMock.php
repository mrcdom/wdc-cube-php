<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Products\ProductsPanelPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Products\ProductsPanelViewState;

final class ProductsPanelViewMock extends AbstractViewMock
{
    public readonly ProductsPanelViewState $state;

    public function __construct(public readonly ProductsPanelPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
