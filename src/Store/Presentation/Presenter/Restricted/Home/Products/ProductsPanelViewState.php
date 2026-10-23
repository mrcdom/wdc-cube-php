<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Home\Products;

use Wdc\Framework\Cube\ViewState;
use Wdc\Store\Presentation\Presenter\Restricted\Products\ProductInfo;

final class ProductsPanelViewState implements ViewState
{
    /** @var list<ProductInfo> */
    public array $products = [];
}
