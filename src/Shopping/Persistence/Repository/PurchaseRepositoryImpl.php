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
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCriteria;
use Wdc\Shopping\Domain\Purchase\PurchaseOrderBy;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Persistence\ShoppingRepositories;

/**
 * Relações: `user` (N:1 via EN_PURCHASE.USERID) e `items` (1:N via EN_PURCHASEITEM.PURCHASEID),
 * ambas resolvidas dentro do mesmo SELECT pela JsonQuery.
 *
 * @extends AbstractRepository<Purchase, PurchaseCriteria>
 */
final class PurchaseRepositoryImpl extends AbstractRepository implements PurchaseRepository
{
    /** @var JsonQuery<Purchase, PurchaseCriteria> */
    private readonly JsonQuery $query;

    public function __construct(Database $db, private readonly ShoppingRepositories $repos)
    {
        parent::__construct($db);
        $table = new Table('EN_PURCHASE', static fn (): Purchase => new Purchase(), [
            new Column('id', 'ID', ColumnType::Int, static fn (Purchase $p) => $p->id(), static fn (Purchase $p, mixed $v) => $p->withId($v)),
            new Column('buyDate', 'BUYDATE', ColumnType::DateTime, static fn (Purchase $p) => $p->buyDate(), static fn (Purchase $p, mixed $v) => $p->withBuyDate($v)),
        ]);
        // As consultas-alvo vêm em closures: compra → item → compra é um ciclo, e quando este
        // construtor roda o repositório de itens ainda nem existe.
        $this->query = new JsonQuery($table, PurchaseCriteria::class, 'pu', $db->dialect())
            ->withConditions($this->conditions(...))
            ->withOrdering($this->orderBy(...))
            ->addToOne(
                'user',
                static fn (Purchase $p) => $p->user(),
                static fn (Purchase $p, ?User $u) => $p->withUser($u),
                fn () => $this->repos->users->query(),
                'USERID',
            )
            ->addToMany(
                'items',
                static fn (Purchase $p) => $p->items(),
                static fn (Purchase $p, array $items) => $p->withItems($items),
                fn () => $this->repos->purchaseItems->query(),
                'PURCHASEID',
            );
    }

    public function query(): JsonQuery
    {
        return $this->query;
    }

    public function newProjection(): object
    {
        return new Purchase()
            ->withId(PV::INT)
            ->withBuyDate(PV::dateTime())
            ->withUser(new User()->withId(PV::INT));   // só o id: sai da coluna USERID, sem subconsulta
    }

    /** Insere a compra e, junto, os itens (cada item passa a apontar para ela). */
    public function insert(object $bean): bool
    {
        $inserted = $this->insertBean($bean, ['USERID' => $bean->userId()]);
        if ($inserted) {
            foreach ($bean->items() ?? [] as $item) {
                $this->repos->purchaseItems->insert($item->withPurchase($bean));
            }
        }

        return $inserted;
    }

    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool
    {
        return $this->updateBean($newBean, $oldBean, $projection, [
            'USERID' => [static fn (Purchase $p) => $p->userId(), ColumnType::Int],
        ]);
    }

    /** Sem ON DELETE CASCADE no schema: apaga os itens antes. */
    public function delete(object $criteria): int
    {
        $ctx = new QueryContext();
        $alias = $this->query->newAlias($ctx);
        [$where, $params] = Sql::where($this->conditions($criteria, $alias, $ctx));
        $this->db->execute(
            "DELETE FROM EN_PURCHASEITEM WHERE PURCHASEID IN (SELECT {$alias}.ID FROM EN_PURCHASE {$alias}{$where})",
            $params,
        );

        return $this->deleteWhere($criteria);
    }

    protected function sequence(): string
    {
        return 'SQ_PURCHASE';
    }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        return Sql::and([
            CT::translate("{$a}.ID", $criteria->purchaseId()),
            CT::translate("{$a}.USERID", $criteria->userId()),
            // ColumnType::formatDate(...) é a sintaxe "first-class callable": vira uma Closure
            CT::translate("{$a}.BUYDATE", $criteria->buyDate(), ColumnType::formatDate(...)),
            $this->existsItemMatching($criteria, $a, $ctx),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        return match ($criteria?->orderBy()) {
            null => '',
            PurchaseOrderBy::OLDEST_FIRST => "{$a}.ID ASC",
            PurchaseOrderBy::NEWEST_FIRST => "{$a}.ID DESC",
            PurchaseOrderBy::MOST_RECENT_PURCHASE_FIRST => "{$a}.BUYDATE DESC, {$a}.ID DESC",
            PurchaseOrderBy::EARLIEST_PURCHASE_FIRST => "{$a}.BUYDATE ASC, {$a}.ID ASC",
        };
    }

    protected function projectionOf(object $criteria): ?object
    {
        return $criteria->projection();
    }

    protected function criteriaForIds(array $ids, object $projection): object
    {
        return new PurchaseCriteria()->purchaseId()->in($ids)->withProjection($projection);
    }

    /** Campo virtual `productId`: existe item desta compra com o produto pedido. */
    private function existsItemMatching(PurchaseCriteria $criteria, string $a, QueryContext $ctx): ?Sql
    {
        $pi = $ctx->alias('pi');
        $onProduct = CT::translate("{$pi}.PRODUCTID", $criteria->productId());
        if ($onProduct === null) {
            return null;
        }

        return new Sql(
            "EXISTS (SELECT 1 FROM EN_PURCHASEITEM {$pi} WHERE {$pi}.PURCHASEID = {$a}.ID AND {$onProduct->sql})",
            $onProduct->params,
        );
    }
}
