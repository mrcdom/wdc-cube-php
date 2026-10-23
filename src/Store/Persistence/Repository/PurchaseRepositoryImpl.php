<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence\Repository;

use LogicException;
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
use Wdc\Store\Domain\Purchase\Purchase;
use Wdc\Store\Domain\Purchase\PurchaseCriteria;
use Wdc\Store\Domain\Purchase\PurchaseOrderBy;
use Wdc\Store\Domain\Purchase\PurchaseRepository;
use Wdc\Store\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Store\Domain\User\User;
use Wdc\Store\Domain\User\UserRepository;

/**
 * Relations: `user` (N:1 via EN_PURCHASE.USERID) and `items` (1:N via EN_PURCHASEITEM.PURCHASEID),
 * both resolved inside the same SELECT by JsonQuery.
 *
 * @extends AbstractRepository<Purchase, PurchaseCriteria>
 */
final class PurchaseRepositoryImpl extends AbstractRepository implements PurchaseRepository
{
    /*
     * Table, sequence and column names - this repository owns them. They are public
     * because other repositories use them too (EXISTS, cascading DELETE).
     */
    public const string TABLE = 'EN_PURCHASE';
    public const string SEQUENCE = 'SQ_PURCHASE';
    public const string ID = 'ID';
    public const string USERID = 'USERID';
    public const string BUYDATE = 'BUYDATE';

    /** @var JsonQuery<Purchase, PurchaseCriteria> */
    private readonly JsonQuery $query;

    /*
     * The repositories this one needs: looked up in Beans the first time (at construction they
     * may not be registered yet) and kept.
     */
    private ?UserRepositoryImpl $userRepository = null;

    private ?PurchaseItemRepositoryImpl $purchaseItemRepository = null;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $table = new Table(self::TABLE, static fn (): Purchase => new Purchase(), [
            new Column('id', self::ID, ColumnType::Int, static fn (Purchase $p) => $p->id(), static fn (Purchase $p, mixed $v) => $p->withId($v)),
            new Column('buyDate', self::BUYDATE, ColumnType::DateTime, static fn (Purchase $p) => $p->buyDate(), static fn (Purchase $p, mixed $v) => $p->withBuyDate($v)),
        ]);
        // The target queries come as closures: purchase → item → purchase is a cycle, and when this
        // constructor runs the item repository does not exist yet.
        $this->query = new JsonQuery($table, PurchaseCriteria::class, 'pu', $db->dialect())
            ->withConditions($this->conditions(...))
            ->withOrdering($this->orderBy(...))
            ->addToOne(
                'user',
                static fn (Purchase $p) => $p->user(),
                static fn (Purchase $p, ?User $u) => $p->withUser($u),
                fn () => $this->userRepository()->query(),
                self::USERID,
            )
            ->addToMany(
                'items',
                static fn (Purchase $p) => $p->items(),
                static fn (Purchase $p, array $items) => $p->withItems($items),
                fn () => $this->purchaseItemRepository()->query(),
                PurchaseItemRepositoryImpl::PURCHASEID,
            );
    }

    /** Looked up in Beans on the first call and kept; later calls return the field. */
    private function userRepository(): UserRepositoryImpl
    {
        return $this->userRepository ??= self::repositoryOf(UserRepository::class, UserRepositoryImpl::class);
    }

    /** Looked up in Beans on the first call and kept; later calls return the field. */
    private function purchaseItemRepository(): PurchaseItemRepositoryImpl
    {
        return $this->purchaseItemRepository ??= self::repositoryOf(PurchaseItemRepository::class, PurchaseItemRepositoryImpl::class);
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
            ->withUser(new User()->withId(PV::INT));   // only the id: comes from the USERID column, no subquery
    }

    /** Inserts the purchase together with its items (each item is made to point to it). */
    public function insert(object $bean): bool
    {
        $inserted = $this->insertBean($bean, [self::USERID => $bean->userId()]);
        if ($inserted) {
            foreach ($bean->items() ?? [] as $item) {
                $this->purchaseItemRepository()->insert($item->withPurchase($bean));
            }
        }

        return $inserted;
    }

    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool
    {
        return $this->updateBean($newBean, $oldBean, $projection, [
            self::USERID => [static fn (Purchase $p) => $p->userId(), ColumnType::Int],
        ]);
    }

    /** No ON DELETE CASCADE in the schema: deletes the items first. */
    public function delete(object $criteria): int
    {
        $ctx = new QueryContext();
        $alias = $this->query->newAlias($ctx);
        [$where, $params] = Sql::where($this->conditions($criteria, $alias, $ctx));
        $this->db->execute(
            'DELETE FROM ' . PurchaseItemRepositoryImpl::TABLE . ' WHERE ' . PurchaseItemRepositoryImpl::PURCHASEID
                . " IN (SELECT {$alias}." . self::ID . ' FROM ' . self::TABLE . " {$alias}{$where})",
            $params,
        );

        return $this->deleteWhere($criteria);
    }

    protected function sequence(): string
    {
        return self::SEQUENCE;
    }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        $c = Sql::alias($a);

        return Sql::and([
            CT::translate($c(self::ID), $criteria->purchaseId()),
            CT::translate($c(self::USERID), $criteria->userId()),
            // ColumnType::formatDate(...) is the "first-class callable" syntax: it becomes a Closure
            CT::translate($c(self::BUYDATE), $criteria->buyDate(), ColumnType::formatDate(...)),
            $this->existsItemMatching($criteria, $a, $ctx),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        $c = Sql::alias($a);

        return match ($criteria?->orderBy()) {
            null => '',
            PurchaseOrderBy::OLDEST_FIRST => "{$c(self::ID)} ASC",
            PurchaseOrderBy::NEWEST_FIRST => "{$c(self::ID)} DESC",
            PurchaseOrderBy::MOST_RECENT_PURCHASE_FIRST => "{$c(self::BUYDATE)} DESC, {$c(self::ID)} DESC",
            PurchaseOrderBy::EARLIEST_PURCHASE_FIRST => "{$c(self::BUYDATE)} ASC, {$c(self::ID)} ASC",
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

    /** Virtual field `productId`: this purchase has an item with the requested product. */
    private function existsItemMatching(PurchaseCriteria $criteria, string $a, QueryContext $ctx): ?Sql
    {
        if (!$criteria->productId()->isSet()) {
            return null;   // field not requested: do not even reserve an alias
        }
        $pi = $ctx->alias('pi');
        $i = Sql::alias($pi);
        $onProduct = CT::translate($i(PurchaseItemRepositoryImpl::PRODUCTID), $criteria->productId())
            ?? throw new LogicException('criterion set but produced no condition');   // cannot happen: isSet() above
        $c = Sql::alias($a);

        return new Sql(
            'EXISTS (SELECT 1 FROM ' . PurchaseItemRepositoryImpl::TABLE
                . " {$pi} WHERE {$i(PurchaseItemRepositoryImpl::PURCHASEID)} = {$c(self::ID)} AND {$onProduct->sql})",
            $onProduct->params,
        );
    }
}
