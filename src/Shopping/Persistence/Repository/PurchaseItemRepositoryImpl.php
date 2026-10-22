<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Repository;

use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Persistence\AbstractRepository;
use Wdc\Framework\Persistence\Column;
use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\CriterionTranslator as CT;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonQuery;
use Wdc\Framework\Persistence\Json\QueryContext;
use Wdc\Framework\Persistence\Sql;
use Wdc\Framework\Persistence\Table;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemOrderBy;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Shopping\Persistence\ShoppingRepositories;

/**
 * Relações: `purchase` (via PURCHASEID) e `product` (via PRODUCTID), ambas N:1.
 *
 * @extends AbstractRepository<PurchaseItem, PurchaseItemCriteria>
 */
final class PurchaseItemRepositoryImpl extends AbstractRepository implements PurchaseItemRepository
{
    /** @var JsonQuery<PurchaseItem, PurchaseItemCriteria> */
    private readonly JsonQuery $query;

    public function __construct(Database $db, private readonly ShoppingRepositories $repos)
    {
        parent::__construct($db);
        $table = new Table('EN_PURCHASEITEM', static fn (): PurchaseItem => new PurchaseItem(), [
            new Column('id', 'ID', ColumnType::Int, static fn (PurchaseItem $i) => $i->id(), static fn (PurchaseItem $i, mixed $v) => $i->withId($v)),
            new Column('amount', 'AMOUNT', ColumnType::Int, static fn (PurchaseItem $i) => $i->amount(), static fn (PurchaseItem $i, mixed $v) => $i->withAmount($v)),
            new Column('price', 'PRICE', ColumnType::Float, static fn (PurchaseItem $i) => $i->price(), static fn (PurchaseItem $i, mixed $v) => $i->withPrice($v)),
        ]);
        $this->query = new JsonQuery($table, PurchaseItemCriteria::class, 'pi', $db->dialect())
            ->withConditions($this->conditions(...))
            ->withOrdering($this->orderBy(...))
            ->addToOne(
                'purchase',
                static fn (PurchaseItem $i) => $i->purchase(),
                static fn (PurchaseItem $i, ?Purchase $p) => $i->withPurchase($p),
                fn () => $this->repos->purchases->query(),
                'PURCHASEID',
            )
            ->addToOne(
                'product',
                static fn (PurchaseItem $i) => $i->product(),
                static fn (PurchaseItem $i, ?Product $p) => $i->withProduct($p),
                fn () => $this->repos->products->query(),
                'PRODUCTID',
            );
    }

    public function query(): JsonQuery
    {
        return $this->query;
    }

    public function newProjection(): object
    {
        return new PurchaseItem()
            ->withId(PV::INT)
            ->withAmount(PV::INT)
            ->withPrice(PV::FLOAT)
            ->withPurchase(new Purchase()->withId(PV::INT))
            ->withProduct(new Product()->withId(PV::INT));
    }

    public function insert(object $bean): bool
    {
        return $this->insertBean($bean, [
            'PURCHASEID' => $bean->purchaseId(),
            'PRODUCTID' => $bean->productId(),
        ]);
    }

    /** Cuidado: PURCHASEID vem de purchaseId() e PRODUCTID de productId() — não troque. */
    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool
    {
        return $this->updateBean($newBean, $oldBean, $projection, [
            'PURCHASEID' => [static fn (PurchaseItem $i) => $i->purchaseId(), ColumnType::Int],
            'PRODUCTID' => [static fn (PurchaseItem $i) => $i->productId(), ColumnType::Int],
        ]);
    }

    public function delete(object $criteria): int
    {
        return $this->deleteWhere($criteria);
    }

    protected function sequence(): string
    {
        return 'SQ_PURCHASEITEM';
    }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        return Sql::and([
            CT::translate("{$a}.ID", $criteria->purchaseItemId()),
            CT::translate("{$a}.PURCHASEID", $criteria->purchaseId()),
            CT::translate("{$a}.PRODUCTID", $criteria->productId()),
            CT::translate("{$a}.AMOUNT", $criteria->amount()),
            CT::translate("{$a}.PRICE", $criteria->price()),
            $this->existsPurchaseOfUser($criteria, $a, $ctx),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        return match ($criteria?->orderBy()) {
            null => '',
            PurchaseItemOrderBy::OLDEST_FIRST => "{$a}.ID ASC",
            PurchaseItemOrderBy::NEWEST_FIRST => "{$a}.ID DESC",
            PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST => "{$a}.PRICE DESC, {$a}.ID ASC",
            PurchaseItemOrderBy::LARGEST_QUANTITY_FIRST => "{$a}.AMOUNT DESC, {$a}.ID ASC",
        };
    }

    protected function projectionOf(object $criteria): ?object
    {
        return $criteria->projection();
    }

    protected function criteriaForIds(array $ids, object $projection): object
    {
        return new PurchaseItemCriteria()->purchaseItemId()->in($ids)->withProjection($projection);
    }

    /** Campo virtual `userId`: o dono da compra deste item. */
    private function existsPurchaseOfUser(PurchaseItemCriteria $criteria, string $a, QueryContext $ctx): ?Sql
    {
        $pu = $ctx->alias('pu');
        $onUser = CT::translate("{$pu}.USERID", $criteria->userId());
        if ($onUser === null) {
            return null;
        }

        return new Sql(
            "EXISTS (SELECT 1 FROM EN_PURCHASE {$pu} WHERE {$pu}.ID = {$a}.PURCHASEID AND {$onUser->sql})",
            $onUser->params,
        );
    }
}
