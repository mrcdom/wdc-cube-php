<?php

declare(strict_types=1);

namespace Wdc\Store\Domain\Product;

use Wdc\Framework\Domain\Repository\Repository;

/**
 * @extends Repository<Product, ProductCriteria>
 */
interface ProductRepository extends Repository
{
    /**
     * The product's PNG bytes; null if it has no image.
     *
     * @throws \Wdc\Framework\Domain\Exception\BusinessException if the product does not exist
     */
    public function fetchImage(int $productId): ?string;

    public function updateImage(int $productId, ?string $image): bool;
}
