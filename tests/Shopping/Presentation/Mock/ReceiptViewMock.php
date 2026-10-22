<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptViewState;

final class ReceiptViewMock extends AbstractViewMock
{
    public readonly ReceiptViewState $state;

    public function __construct(public readonly ReceiptPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;
    }
}
