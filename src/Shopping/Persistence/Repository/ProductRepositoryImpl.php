<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Repository;

use LogicException;
use Wdc\Framework\Domain\Exception\BusinessException;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Persistence\AbstractRepository;
use Wdc\Framework\Persistence\Blob;
use Wdc\Framework\Persistence\Column;
use Wdc\Framework\Persistence\ColumnType;
use Wdc\Framework\Persistence\Sql;
use Wdc\Framework\Persistence\CriterionTranslator as CT;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Json\JsonQuery;
use Wdc\Framework\Persistence\Json\QueryContext;
use Wdc\Framework\Persistence\Table;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Domain\Product\ProductOrderBy;
use Wdc\Shopping\Domain\Product\ProductRepository;

/**
 * The methods take `object $criteria` (not ProductCriteria) because PHP does not allow
 * narrowing a parameter type when overriding a method (Liskov rule). The exact type
 * comes from PHPStan generics: `@extends AbstractRepository<Product, ProductCriteria>`.
 *
 * @extends AbstractRepository<Product, ProductCriteria>
 */
final class ProductRepositoryImpl extends AbstractRepository implements ProductRepository
{
    /*
     * Table, sequence and column names - this repository owns them. They are public
     * because other repositories use them too (EXISTS, cascading DELETE).
     */
    public const string TABLE = 'EN_PRODUCT';
    public const string SEQUENCE = 'SQ_PRODUCT';
    public const string ID = 'ID';
    public const string NAME = 'NAME';
    public const string PRICE = 'PRICE';
    public const string DESCRIPTION = 'DESCRIPTION';
    public const string IMAGE = 'IMAGE';

    /** @var JsonQuery<Product, ProductCriteria> */
    private readonly JsonQuery $query;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $table = new Table(self::TABLE, static fn (): Product => new Product(), [
            new Column('id', self::ID, ColumnType::Int, static fn (Product $p) => $p->id(), static fn (Product $p, mixed $v) => $p->withId($v)),
            new Column('name', self::NAME, ColumnType::Text, static fn (Product $p) => $p->name(), static fn (Product $p, mixed $v) => $p->withName($v)),
            new Column('price', self::PRICE, ColumnType::Float, static fn (Product $p) => $p->price(), static fn (Product $p, mixed $v) => $p->withPrice($v)),
            new Column('description', self::DESCRIPTION, ColumnType::Text, static fn (Product $p) => $p->description(), static fn (Product $p, mixed $v) => $p->withDescription($v)),
            new Column('image', self::IMAGE, ColumnType::Blob, static fn (Product $p) => $p->image(), static fn (Product $p, mixed $v) => $p->withImage($v)),
        ]);
        // $this->conditions(...) turns the method into a Closure (which can see protected members)
        $this->query = new JsonQuery($table, ProductCriteria::class, 'pr', $db->dialect())
            ->withConditions($this->conditions(...))
            ->withOrdering($this->orderBy(...));
    }

    public function query(): JsonQuery
    {
        return $this->query;
    }

    public function newProjection(): object
    {
        // PHP 8.4: `new X()->method()` without extra parentheses around the new
        return new Product()
            ->withId(PV::INT)
            ->withName(PV::STR)
            ->withPrice(PV::FLOAT)
            ->withDescription(PV::STR);   // no image: only when requested
    }

    public function insert(object $bean): bool
    {
        return $this->insertBean($bean);
    }

    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool
    {
        return $this->updateBean($newBean, $oldBean, $projection);
    }

    public function delete(object $criteria): int
    {
        if (!$criteria->hasProductId()) {
            throw new LogicException('Product delete requires productId (guard against DELETE without WHERE)');
        }
        return $this->deleteWhere($criteria);
    }

    public function fetchImage(int $productId): ?string
    {
        $rows = $this->db->fetchAll('SELECT ' . self::IMAGE . ' FROM ' . self::TABLE . ' WHERE ' . self::ID . ' = ?', [$productId]);
        if ($rows === []) {
            throw new BusinessException("Product {$productId} not found");
        }
        $image = $rows[0]['image'];

        return $image === null ? null : (string) $image;
    }

    public function updateImage(int $productId, ?string $image): bool
    {
        return $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET ' . self::IMAGE . ' = ? WHERE ' . self::ID . ' = ?',
            [$image === null ? null : new Blob($image), $productId],
        ) > 0;
    }

    protected function sequence(): string
    {
        return self::SEQUENCE;
    }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        $c = Sql::alias($a);

        return Sql::and([
            CT::translate($c(self::ID), $criteria->productId()),
            CT::translate($c(self::NAME), $criteria->name()),
            CT::translate($c(self::PRICE), $criteria->price()),
            CT::translate($c(self::DESCRIPTION), $criteria->description()),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        $c = Sql::alias($a);

        return match ($criteria?->orderBy()) {
            null => '',
            ProductOrderBy::OLDEST_FIRST => "{$c(self::ID)} ASC",
            ProductOrderBy::NEWEST_FIRST => "{$c(self::ID)} DESC",
            ProductOrderBy::NAME_A_TO_Z => "{$c(self::NAME)} ASC, {$c(self::ID)} ASC",
            ProductOrderBy::CHEAPEST_FIRST => "{$c(self::PRICE)} ASC, {$c(self::ID)} ASC",
            ProductOrderBy::MOST_EXPENSIVE_FIRST => "{$c(self::PRICE)} DESC, {$c(self::ID)} ASC",
        };
    }

    protected function projectionOf(object $criteria): ?object
    {
        return $criteria->projection();
    }

    protected function criteriaForIds(array $ids, object $projection): object
    {
        return new ProductCriteria()->productId()->in($ids)->withProjection($projection);
    }
}
