<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home\Products;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Cube\AbstractChildPresenter;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomePresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductService;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * The home's product catalog.
 *
 * @extends AbstractChildPresenter<ShoppingApplication>
 */
final class ProductsPanelPresenter extends AbstractChildPresenter
{
    private const int MAX_PRODUCTS = 1000;

    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly ProductsPanelViewState $state;

    private readonly ProductService $service;

    public function __construct(ShoppingApplication $app, public readonly HomePresenter $owner)
    {
        parent::__construct($app);
        $this->state = new ProductsPanelViewState();
        $this->service = new ProductService();
    }

    public function onOpenProduct(?int $productId): void
    {
        $this->owner->onOpenProduct($productId);
    }

    public function loadProducts(): void
    {
        try {
            $this->state->products = $this->service->loadProductsWithoutDescription(self::MAX_PRODUCTS);
            $this->update();
        } catch (Throwable $caught) {
            $this->app->logger()->error('Failed to load products', ['exception' => $caught]);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('a1b2c3d4e5f6', function (int $event, int $count, array $form): void {
            match ($event) {
                1 => $this->onOpenProduct(Coerce::asInt($form['p.productId'] ?? null)),
                default => Skeleton::unknownEvent($event),
            };
        });
    }

    protected function onCreateView(): CubeView
    {
        return (self::$createView ?? throw new LogicException('ProductsPanelPresenter::$createView not configured'))($this);
    }

    protected function onInitialize(): void
    {
        $this->loadProducts();
    }
}
