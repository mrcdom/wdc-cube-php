<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

final class ReceiptForm
{
    /** purchase date in milliseconds since 1970 */
    public ?int $date = null;

    /** @var list<ReceiptItem> */
    public array $items = [];

    public ?float $total = null;
}
