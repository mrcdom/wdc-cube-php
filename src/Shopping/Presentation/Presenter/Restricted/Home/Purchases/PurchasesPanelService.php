<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases;

use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Pagination\Page;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCriteria;
use Wdc\Shopping\Domain\Purchase\PurchaseOrderBy;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\PurchaseInfo;

final class PurchasesPanelService
{
    /** Data usada quando a compra não tem data (sentinela fixa). */
    private const int NO_DATE_MILLIS = 115754400000;

    private readonly PurchaseRepository $purchases;

    public function __construct()
    {
        $this->purchases = Beans::of(PurchaseRepository::class);
    }

    /** @return list<PurchaseInfo> */
    public function loadPurchases(PurchaseCriteria $criteria): array
    {
        return $this->toInfos($this->purchases->fetch($criteria->withProjection(self::projection())));
    }

    public function countPurchasesOfUser(int $userId): int
    {
        return $this->purchases->count(new PurchaseCriteria()->withUserId($userId));
    }

    /** @return list<PurchaseInfo> mais recentes primeiro */
    public function loadPurchasesOfUser(int $userId, int $offset = 0, int $limit = 0): array
    {
        return $this->toInfos($this->purchases->fetch(self::ofUser($userId), $offset, $limit));
    }

    /** @return Page<PurchaseInfo> */
    public function fetchPageOfUser(int $userId, int $page, int $pageSize): Page
    {
        $result = $this->purchases->fetchPage(self::ofUser($userId), $page, $pageSize);

        return new Page($this->toInfos($result->items), $result->page, $result->totalPages, $result->totalItems);
    }

    private static function ofUser(int $userId): PurchaseCriteria
    {
        return new PurchaseCriteria()
            ->withUserId($userId)
            ->withProjection(self::projection())
            ->withOrderBy(PurchaseOrderBy::MOST_RECENT_PURCHASE_FIRST);
    }

    /** id, data e itens (preço, quantidade, nome do produto): uma consulta só, graças à projeção JSON. */
    private static function projection(): Purchase
    {
        $item = new PurchaseItem()
            ->withPrice(PV::FLOAT)
            ->withAmount(PV::INT)
            ->withProduct(new Product()->withName(PV::STR));

        return new Purchase()->withId(PV::INT)->withBuyDate(PV::dateTime())->withItems([$item]);
    }

    /**
     * @param list<Purchase> $purchases
     * @return list<PurchaseInfo>
     */
    private function toInfos(array $purchases): array
    {
        return array_map(self::toInfo(...), $purchases);
    }

    private static function toInfo(Purchase $purchase): PurchaseInfo
    {
        $info = new PurchaseInfo();
        $info->id = $purchase->id() ?? -1;
        $buyDate = $purchase->buyDate();
        $info->date = $buyDate === null ? self::NO_DATE_MILLIS : (int) $buyDate->format('Uv');   // U = segundos, v = milissegundos
        $total = 0.0;
        foreach ($purchase->items() ?? [] as $item) {
            $total += ($item->price() ?? 0.0) * ($item->amount() ?? 0);
            $name = $item->product()?->name();
            if ($name !== null && trim($name) !== '') {
                $info->items[] = $name;
            }
        }
        $info->total = $total;

        return $info;
    }
}
