<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Products;

use Wdc\Framework\Cube\ViewState;

final class ProductViewState implements ViewState
{
    public ?ProductInfo $product = null;

    public int $errorCode = 0;

    public ?string $errorMessage = null;
}
