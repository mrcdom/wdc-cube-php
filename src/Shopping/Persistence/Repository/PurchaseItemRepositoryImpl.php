<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Repository;

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
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductRepository;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemOrderBy;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;

/**
 * Relações: `purchase` (via PURCHASEID) e `product` (via PRODUCTID), ambas N:1.
 *
 * @extends AbstractRepository<PurchaseItem, PurchaseItemCriteria>
 */
final class PurchaseItemRepositoryImpl extends AbstractRepository implements PurchaseItemRepository
{
    /*
     * Nomes da tabela, da sequência e das colunas - este repositório é o dono deles. São
     * públicos porque outros repositórios também os usam (EXISTS, DELETE em cascata).
     */
    public const string TABLE = 'EN_PURCHASEITEM';
    public const string SEQUENCE = 'SQ_PURCHASEITEM';
    public const string ID = 'ID';
    public const string PURCHASEID = 'PURCHASEID';
    public const string PRODUCTID = 'PRODUCTID';
    public const string AMOUNT = 'AMOUNT';
    public const string PRICE = 'PRICE';

    /** @var JsonQuery<PurchaseItem, PurchaseItemCriteria> */
    private readonly JsonQuery $query;

    /*
     * Os repositórios de que este precisa: achados no Beans na primeira vez (na construção eles
     * podem ainda não estar registrados) e guardados.
     */
    private ?PurchaseRepositoryImpl $purchaseRepository = null;

    private ?ProductRepositoryImpl $productRepository = null;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $table = new Table(self::TABLE, static fn (): PurchaseItem => new PurchaseItem(), [
            new Column('id', self::ID, ColumnType::Int, static fn (PurchaseItem $i) => $i->id(), static fn (PurchaseItem $i, mixed $v) => $i->withId($v)),
            new Column('amount', self::AMOUNT, ColumnType::Int, static fn (PurchaseItem $i) => $i->amount(), static fn (PurchaseItem $i, mixed $v) => $i->withAmount($v)),
            new Column('price', self::PRICE, ColumnType::Float, static fn (PurchaseItem $i) => $i->price(), static fn (PurchaseItem $i, mixed $v) => $i->withPrice($v)),
        ]);
        $this->query = new JsonQuery($table, PurchaseItemCriteria::class, 'pi', $db->dialect())
            ->withConditions($this->conditions(...))
            ->withOrdering($this->orderBy(...))
            ->addToOne(
                'purchase',
                static fn (PurchaseItem $i) => $i->purchase(),
                static fn (PurchaseItem $i, ?Purchase $p) => $i->withPurchase($p),
                fn () => $this->purchaseRepository()->query(),
                self::PURCHASEID,
            )
            ->addToOne(
                'product',
                static fn (PurchaseItem $i) => $i->product(),
                static fn (PurchaseItem $i, ?Product $p) => $i->withProduct($p),
                fn () => $this->productRepository()->query(),
                self::PRODUCTID,
            );
    }

    /** Achado no Beans na primeira chamada e guardado; as seguintes devolvem o campo. */
    private function purchaseRepository(): PurchaseRepositoryImpl
    {
        return $this->purchaseRepository ??= self::repositoryOf(PurchaseRepository::class, PurchaseRepositoryImpl::class);
    }

    /** Achado no Beans na primeira chamada e guardado; as seguintes devolvem o campo. */
    private function productRepository(): ProductRepositoryImpl
    {
        return $this->productRepository ??= self::repositoryOf(ProductRepository::class, ProductRepositoryImpl::class);
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
            self::PURCHASEID => $bean->purchaseId(),
            self::PRODUCTID => $bean->productId(),
        ]);
    }

    /** Cuidado: PURCHASEID vem de purchaseId() e PRODUCTID de productId() — não troque. */
    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool
    {
        return $this->updateBean($newBean, $oldBean, $projection, [
            self::PURCHASEID => [static fn (PurchaseItem $i) => $i->purchaseId(), ColumnType::Int],
            self::PRODUCTID => [static fn (PurchaseItem $i) => $i->productId(), ColumnType::Int],
        ]);
    }

    public function delete(object $criteria): int
    {
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
            CT::translate($c(self::ID), $criteria->purchaseItemId()),
            CT::translate($c(self::PURCHASEID), $criteria->purchaseId()),
            CT::translate($c(self::PRODUCTID), $criteria->productId()),
            CT::translate($c(self::AMOUNT), $criteria->amount()),
            CT::translate($c(self::PRICE), $criteria->price()),
            $this->existsPurchaseOfUser($criteria, $a, $ctx),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        $c = Sql::alias($a);

        return match ($criteria?->orderBy()) {
            null => '',
            PurchaseItemOrderBy::OLDEST_FIRST => "{$c(self::ID)} ASC",
            PurchaseItemOrderBy::NEWEST_FIRST => "{$c(self::ID)} DESC",
            PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST => "{$c(self::PRICE)} DESC, {$c(self::ID)} ASC",
            PurchaseItemOrderBy::LARGEST_QUANTITY_FIRST => "{$c(self::AMOUNT)} DESC, {$c(self::ID)} ASC",
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
        if (!$criteria->userId()->isSet()) {
            return null;   // campo não pedido: nem reserva alias
        }
        $pu = $ctx->alias('pu');
        $p = Sql::alias($pu);
        $onUser = CT::translate($p(PurchaseRepositoryImpl::USERID), $criteria->userId())
            ?? throw new LogicException('critério preenchido sem condição');   // não acontece: isSet() acima
        $c = Sql::alias($a);

        return new Sql(
            'EXISTS (SELECT 1 FROM ' . PurchaseRepositoryImpl::TABLE
                . " {$pu} WHERE {$p(PurchaseRepositoryImpl::ID)} = {$c(self::PURCHASEID)} AND {$onUser->sql})",
            $onUser->params,
        );
    }
}
