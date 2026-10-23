<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Presentation\Exception\WrongParametersException;

final class ReceiptService
{
    private readonly PurchaseRepository $purchases;

    public function __construct()
    {
        $this->purchases = Beans::of(PurchaseRepository::class);
    }

    public function loadReceipt(?int $purchaseId): ?ReceiptForm
    {
        if ($purchaseId === null) {
            throw new WrongParametersException();
        }
        $projection = new Purchase()->withBuyDate(PV::dateTime())->withItems([ReceiptItem::projection()]);
        $receipt = self::toReceipt($this->purchases->fetchById($purchaseId, $projection));
        if ($receipt !== null) {
            usort($receipt->items, static fn (ReceiptItem $a, ReceiptItem $b): int => $a->id <=> $b->id);   // <=> "nave espacial": -1, 0 ou 1
        }

        return $receipt;
    }

    public static function toReceipt(?Purchase $purchase): ?ReceiptForm
    {
        if ($purchase === null) {
            return null;
        }
        $receipt = new ReceiptForm();
        $receipt->date = $purchase->buyDate() === null ? null : (int) $purchase->buyDate()->format('Uv');
        $total = 0.0;
        foreach ($purchase->items() ?? [] as $item) {
            if ($item->price() !== null) {
                $total += $item->price() * ($item->amount() ?? 0);
            }
            $receipt->items[] = ReceiptItem::create($item);
        }
        $receipt->total = $total;

        return $receipt;
    }
}
