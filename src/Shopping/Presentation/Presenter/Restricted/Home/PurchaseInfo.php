<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home;

/** Uma compra no extrato da home. */
final class PurchaseInfo
{
    public int $id = -1;

    /** data da compra em milissegundos desde 1970 (o que o React espera) */
    public int $date = 0;

    public float $total = 0.0;

    /** @var list<string> nomes dos produtos */
    public array $items = [];
}
