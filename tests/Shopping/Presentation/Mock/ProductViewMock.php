<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductViewState;

final class ProductViewMock extends AbstractViewMock
{
    public readonly ProductViewState $state;

    public function __construct(public readonly ProductPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
