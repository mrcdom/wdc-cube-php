<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Home;

/** A purchase in the home's purchase history. */
final class PurchaseInfo
{
    public int $id = -1;

    /** purchase date in milliseconds since 1970 (what React expects) */
    public int $date = 0;

    public float $total = 0.0;

    /** @var list<string> product names */
    public array $items = [];
}
