<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Presentation\Exception\PurchaseNotFoundException;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomeKeys;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * Recibo de uma compra (`receipt?rc-id=N`).
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
        $this->receiptService = new ReceiptService(Beans::of(PurchaseRepository::class));
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $keys = new ReceiptKeys($this->app, $intent);
        $this->state->notifySuccess = $keys->purchaseMade;
        $purchaseId = $keys->purchaseId ?? throw new PurchaseNotFoundException();   // ausente ou inválido

        if ($this->state->receipt === null || $purchaseId !== $this->purchaseId) {
            $this->state->receipt = $this->receiptService->loadReceipt($purchaseId) ?? throw new PurchaseNotFoundException();
            $this->purchaseId = $purchaseId;
            $this->update();
        }
        if ($initialization) {
            $this->view = (self::$createView ?? throw new LogicException('ReceiptPresenter::$createView não configurado'))($this);
            $this->update();
        }
        $this->ownerSlot = $keys->ownerSlot;   // a cada viagem: quem decide o slot é o dono acima
        $this->ownerSlot?->setView($this->view);

        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        $keys = new ReceiptKeys($this->app, $intent);
        $keys->purchaseId = $this->purchaseId;
    }

    public function onOpenProducts(): void
    {
        try {
            HomeKeys::of($this->app)->go();
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
