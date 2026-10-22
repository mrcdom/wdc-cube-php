<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\Product;

use Wdc\Framework\Domain\Repository\Repository;

/**
 * @extends Repository<Product, ProductCriteria>
 */
interface ProductRepository extends Repository
{
    /**
     * Bytes do PNG do produto; null se não tem imagem.
     *
     * @throws \Wdc\Framework\Domain\Exception\BusinessException se o produto não existe
     */
    public function fetchImage(int $productId): ?string;

    public function updateImage(int $productId, ?string $image): bool;
}
