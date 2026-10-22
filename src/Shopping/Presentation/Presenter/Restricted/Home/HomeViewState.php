<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home;

use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\ViewState;

final class HomeViewState implements ViewState
{
    /** tela aberta dentro da home (produto, carrinho, recibo); null = mostra os painéis */
    public ?CubeView $contentView = null;

    public ?CubeView $productsPanelView = null;

    public ?CubeView $purchasesPanelView = null;

    public ?string $nickName = null;

    public int $cartItemCount = 0;

    public int $errorCode = 0;

    public ?string $errorMessage = null;
}
