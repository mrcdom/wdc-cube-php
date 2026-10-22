<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

use Wdc\Framework\Cube\ViewState;

final class ReceiptViewState implements ViewState
{
    /** true logo depois da compra: a tela mostra o aviso de sucesso */
    public bool $notifySuccess = false;

    public ?ReceiptForm $receipt = null;
}
