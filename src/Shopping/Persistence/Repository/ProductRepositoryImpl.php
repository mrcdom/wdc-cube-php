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
 * Os métodos recebem `object $criteria` (e não ProductCriteria) porque o PHP não deixa
 * estreitar o tipo de um parâmetro ao sobrescrever um método (regra de Liskov). O tipo
 * exato vem do generics do PHPStan: `@extends AbstractRepository<Product, ProductCriteria>`.
 *
 * @extends AbstractRepository<Product, ProductCriteria>
 */
final class ProductRepositoryImpl extends AbstractRepository implements ProductRepository
{
    /** @var JsonQuery<Product, ProductCriteria> */
    private readonly JsonQuery $query;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $table = new Table('EN_PRODUCT', static fn (): Product => new Product(), [
            new Column('id', 'ID', ColumnType::Int, static fn (Product $p) => $p->id(), static fn (Product $p, mixed $v) => $p->withId($v)),
            new Column('name', 'NAME', ColumnType::Text, static fn (Product $p) => $p->name(), static fn (Product $p, mixed $v) => $p->withName($v)),
            new Column('price', 'PRICE', ColumnType::Float, static fn (Product $p) => $p->price(), static fn (Product $p, mixed $v) => $p->withPrice($v)),
            new Column('description', 'DESCRIPTION', ColumnType::Text, static fn (Product $p) => $p->description(), static fn (Product $p, mixed $v) => $p->withDescription($v)),
            new Column('image', 'IMAGE', ColumnType::Blob, static fn (Product $p) => $p->image(), static fn (Product $p, mixed $v) => $p->withImage($v)),
        ]);
        // $this->conditions(...) transforma o método numa Closure (que enxerga os membros protegidos)
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
        // PHP 8.4: `new X()->metodo()` sem parênteses extras em volta do new
        return new Product()
            ->withId(PV::INT)
            ->withName(PV::STR)
            ->withPrice(PV::FLOAT)
            ->withDescription(PV::STR);   // sem image: só quando pedida
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
            throw new LogicException('Product delete requires productId (proteção contra DELETE sem WHERE)');
        }
        return $this->deleteWhere($criteria);
    }

    public function fetchImage(int $productId): ?string
    {
        $rows = $this->db->fetchAll('SELECT IMAGE FROM EN_PRODUCT WHERE ID = ?', [$productId]);
        if ($rows === []) {
            throw new BusinessException("Product {$productId} not found");
        }
        $image = $rows[0]['image'];

        return $image === null ? null : (string) $image;
    }

    public function updateImage(int $productId, ?string $image): bool
    {
        return $this->db->execute(
            'UPDATE EN_PRODUCT SET IMAGE = ? WHERE ID = ?',
            [$image === null ? null : new Blob($image), $productId],
        ) > 0;
    }

    protected function sequence(): string
    {
        return 'SQ_PRODUCT';
    }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        return Sql::and([
            CT::translate("{$a}.ID", $criteria->productId()),
            CT::translate("{$a}.NAME", $criteria->name()),
            CT::translate("{$a}.PRICE", $criteria->price()),
            CT::translate("{$a}.DESCRIPTION", $criteria->description()),
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        return match ($criteria?->orderBy()) {
            null => '',
            ProductOrderBy::OLDEST_FIRST => "{$a}.ID ASC",
            ProductOrderBy::NEWEST_FIRST => "{$a}.ID DESC",
            ProductOrderBy::NAME_A_TO_Z => "{$a}.NAME ASC, {$a}.ID ASC",
            ProductOrderBy::CHEAPEST_FIRST => "{$a}.PRICE ASC, {$a}.ID ASC",
            ProductOrderBy::MOST_EXPENSIVE_FIRST => "{$a}.PRICE DESC, {$a}.ID ASC",
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
