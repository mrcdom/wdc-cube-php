<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartViewState;

final class CartViewMock extends AbstractViewMock
{
    public readonly CartViewState $state;

    public function __construct(public readonly CartPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
