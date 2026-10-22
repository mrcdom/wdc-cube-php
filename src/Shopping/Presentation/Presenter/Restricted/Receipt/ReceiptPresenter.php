<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Shopping\Presentation\Exception\PurchaseNotFoundException;
use Wdc\Shopping\Presentation\PlaceAttributes;
use Wdc\Shopping\Presentation\PlaceParameters;
use Wdc\Shopping\Presentation\Routes;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * Recibo de uma compra (`receipt?purchaseId=N`).
 *
 * @extends AbstractCubePresenter<ShoppingApplication>
 */
final class ReceiptPresenter extends AbstractCubePresenter
{
    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly ReceiptViewState $state;

    private readonly ReceiptService $receiptService;

    private ?int $purchaseId = null;

    private ?CubeViewSlot $ownerSlot = null;

    public function __construct(ShoppingApplication $app)
    {
        parent::__construct($app);
        $this->state = new ReceiptViewState();
        $this->receiptService = new ReceiptService($app->purchaseRepository());
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $this->state->notifySuccess = $intent->getAttribute(PlaceAttributes::ATTR_PURCHASE_MADE) === true;
        $purchaseId = $intent->getParameterAsInt(PlaceParameters::PURCHASE_ID, $this->purchaseId)
            ?? throw new LogicException('Missing PURCHASE_ID');

        if ($this->state->receipt === null || $purchaseId !== $this->purchaseId) {
            $this->state->receipt = $this->receiptService->loadReceipt($purchaseId) ?? throw new PurchaseNotFoundException();
            $this->purchaseId = $purchaseId;
            $this->update();
        }
        if ($initialization) {
            $this->ownerSlot = $intent->getViewSlot(PlaceAttributes::SLOT_OWNER);
            $this->view = (self::$createView ?? throw new LogicException('ReceiptPresenter::$createView não configurado'))($this);
            $this->update();
        }
        $this->ownerSlot?->setView($this->view);

        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        $intent->setParameter(PlaceParameters::PURCHASE_ID, $this->purchaseId);
    }

    public function onOpenProducts(): void
    {
        try {
            Routes::home($this->app);
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Going to restricted home place', $caught);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('e8d0bd8ae3bc', function (int $event, int $qtde, array $form): void {
            match ($event) {
                1 => $this->onOpenProducts(),
                default => Skeleton::unknownEvent($event),
            };
        });
    }
}
