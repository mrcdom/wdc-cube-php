<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Cart;

use Wdc\Framework\Cube\ViewState;

final class CartViewState implements ViewState
{
    /** @var list<CartItem> */
    public array $items = [];

    public int $errorCode = 0;

    public ?string $errorMessage = null;
}
