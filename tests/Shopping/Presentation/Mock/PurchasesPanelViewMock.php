<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelViewState;

final class PurchasesPanelViewMock extends AbstractViewMock
{
    public readonly PurchasesPanelViewState $state;

    public function __construct(public readonly PurchasesPanelPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
