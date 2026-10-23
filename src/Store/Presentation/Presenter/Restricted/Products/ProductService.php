<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Products;

use Wdc\Framework\Commons\Beans;
use Wdc\Store\Domain\Product\ProductCriteria;
use Wdc\Store\Domain\Product\ProductRepository;
use Wdc\Store\Presentation\Exception\ProductNotFoundException;
use Wdc\Store\Presentation\Exception\WrongParametersException;

final class ProductService
{
    private readonly ProductRepository $products;

    public function __construct()
    {
        $this->products = Beans::of(ProductRepository::class);
    }

    public function loadProductById(?int $productId): ProductInfo
    {
        if ($productId === null) {
            throw new WrongParametersException();
        }

        return ProductInfo::create($this->products->fetchById($productId, ProductInfo::projection()))
            ?? throw new ProductNotFoundException();
    }

    /** @return list<ProductInfo> */
    public function loadProductsWithoutDescription(int $limit): array
    {
        $projection = ProductInfo::projection()->withDescription(null);
        $products = $this->products->fetch(new ProductCriteria()->withProjection($projection), 0, $limit);

        return array_values(array_filter(array_map(ProductInfo::create(...), $products)));
    }
}
