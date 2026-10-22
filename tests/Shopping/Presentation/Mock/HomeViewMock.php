<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomePresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomeViewState;

final class HomeViewMock extends AbstractViewMock
{
    public readonly HomeViewState $state;

    public function __construct(public readonly HomePresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }

    public function productsPanel(): ProductsPanelViewMock
    {
        return ProductsPanelViewMock::cast($this->state->productsPanelView);
    }

    public function purchasesPanel(): PurchasesPanelViewMock
    {
        return PurchasesPanelViewMock::cast($this->state->purchasesPanelView);
    }
}
