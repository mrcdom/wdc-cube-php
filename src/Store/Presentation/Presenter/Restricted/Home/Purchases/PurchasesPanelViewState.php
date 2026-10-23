<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Home\Purchases;

use Wdc\Framework\Cube\ViewState;
use Wdc\Store\Presentation\Presenter\Restricted\Home\PurchaseInfo;

final class PurchasesPanelViewState implements ViewState
{
    /** @var list<PurchaseInfo> */
    public array $purchases = [];

    public int $page = 0;

    /** -1 until the view measures how many items fit on screen */
    public int $pageSize = -1;

    public int $totalCount = 0;
}
