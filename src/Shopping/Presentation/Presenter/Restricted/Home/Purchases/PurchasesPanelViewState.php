<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases;

use Wdc\Framework\Cube\ViewState;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\PurchaseInfo;

final class PurchasesPanelViewState implements ViewState
{
    /** @var list<PurchaseInfo> */
    public array $purchases = [];

    public int $page = 0;

    /** -1 até a view medir quantos itens cabem na tela */
    public int $pageSize = -1;

    public int $totalCount = 0;
}
