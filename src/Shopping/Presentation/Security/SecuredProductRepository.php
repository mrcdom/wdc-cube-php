<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Security;

use Closure;
use Wdc\Framework\Domain\Security\SecurityContext;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Domain\Product\ProductRepository;

/**
 * @extends SecuredRepository<Product, ProductCriteria>
 */
final class SecuredProductRepository extends SecuredRepository implements ProductRepository
{
    /**
     * @param Closure(): ?SecurityContext $context
     */
    public function __construct(private readonly ProductRepository $products, Closure $context)
    {
        parent::__construct($products, $context);
    }

    public function fetchImage(int $productId): ?string
    {
        return $this->run(fn () => $this->products->fetchImage($productId));
    }

    public function updateImage(int $productId, ?string $image): bool
    {
        return $this->run(fn () => $this->products->updateImage($productId, $image));
    }
}
