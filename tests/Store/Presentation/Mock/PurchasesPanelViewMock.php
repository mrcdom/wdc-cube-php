<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Presentation\Mock;

use Wdc\Store\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelPresenter;
use Wdc\Store\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelViewState;

final class PurchasesPanelViewMock extends AbstractViewMock
{
    public readonly PurchasesPanelViewState $state;

    public function __construct(public readonly PurchasesPanelPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
