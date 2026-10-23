<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Receipt;

use Wdc\Framework\Cube\ViewState;

final class ReceiptViewState implements ViewState
{
    /** true right after the purchase: the screen shows the success notice */
    public bool $notifySuccess = false;

    public ?ReceiptForm $receipt = null;
}
