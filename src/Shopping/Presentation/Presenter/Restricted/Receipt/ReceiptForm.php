<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

final class ReceiptForm
{
    /** data da compra em milissegundos desde 1970 */
    public ?int $date = null;

    /** @var list<ReceiptItem> */
    public array $items = [];

    public ?float $total = null;
}
