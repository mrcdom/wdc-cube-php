<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Products;

use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Domain\Product\ProductRepository;
use Wdc\Shopping\Presentation\Exception\ProductNotFoundException;
use Wdc\Shopping\Presentation\Exception\WrongParametersException;

final class ProductService
{
    public function __construct(private readonly ProductRepository $products)
    {
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
