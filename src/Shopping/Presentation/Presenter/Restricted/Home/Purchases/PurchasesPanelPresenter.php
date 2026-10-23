<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Cube\AbstractChildPresenter;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomePresenter;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * Paged purchase history. The page size comes from the view, which measures how many items
 * fit in the available space.
 *
 * @extends AbstractChildPresenter<ShoppingApplication>
 */
final class PurchasesPanelPresenter extends AbstractChildPresenter
{
    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly PurchasesPanelViewState $state;

    private readonly PurchasesPanelService $service;

    public function __construct(ShoppingApplication $app, public readonly HomePresenter $owner)
    {
        parent::__construct($app);
        $this->state = new PurchasesPanelViewState();
        $this->service = new PurchasesPanelService();
    }

    public function onPageChange(int $page): void
    {
        $this->state->page = max(0, $page);
        $this->loadPurchases();
    }

    /** The view reported how many items fit without scrolling. Reloads only if it changed. */
    public function onItemSizeCapacityChanged(int $capacity): void
    {
        $pageSize = max(1, $capacity);
        if ($pageSize !== $this->state->pageSize) {
            $this->state->pageSize = $pageSize;
            $this->state->page = 0;
            $this->loadPurchases();
        }
    }

    public function onOpenReceipt(?int $purchaseId): void
    {
        $this->owner->onOpenReceipt($purchaseId);
    }

    public function loadPurchases(): void
    {
        $subject = $this->app->subject();
        if ($subject === null) {
            return;
        }
        try {
            $result = $this->service->fetchPageOfUser($subject->id, $this->state->page, $this->state->pageSize);
            // page past the end: go back to the last one
            if ($result->items === [] && $result->totalPages > 0) {
                $result = $this->service->fetchPageOfUser($subject->id, $result->totalPages - 1, $this->state->pageSize);
            }
            $this->state->page = $result->page;
            $this->state->totalCount = $result->totalItems;
            $this->state->purchases = $result->items;
            $this->update();
        } catch (Throwable $caught) {
            $this->app->logger()->error('Failed to load purchases', ['exception' => $caught]);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('b3c4d5e6f7a8', function (int $event, int $count, array $form): void {
            match ($event) {
                1 => $this->onOpenReceipt(Coerce::asInt($form['p.purchaseId'] ?? null)),
                2 => $this->onPageChange(Coerce::asInt($form['p.page'] ?? null) ?? 0),
                3 => $this->onItemSizeCapacityChanged(Coerce::asInt($form['p.capacity'] ?? null) ?? 1),
                default => Skeleton::unknownEvent($event),
            };
        });
    }

    protected function onCreateView(): CubeView
    {
        return (self::$createView ?? throw new LogicException('PurchasesPanelPresenter::$createView not configured'))($this);
    }

    /** Only renders: loading waits for the view to measure the space and call onItemSizeCapacityChanged(). */
    protected function onInitialize(): void
    {
        $this->update();
    }
}
