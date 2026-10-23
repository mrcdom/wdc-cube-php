<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Home;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\Registration;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Store\Presentation\Exception\ProductNotFoundException;
use Wdc\Store\Presentation\Exception\PurchaseNotFoundException;
use Wdc\Store\Presentation\Presenter\Open\Login\LoginKeys;
use Wdc\Store\Presentation\Presenter\Restricted\Cart\CartKeys;
use Wdc\Store\Presentation\Presenter\Restricted\Cart\CartManager;
use Wdc\Store\Presentation\Presenter\Restricted\Home\Products\ProductsPanelPresenter;
use Wdc\Store\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelPresenter;
use Wdc\Store\Presentation\Presenter\Restricted\Products\ProductKeys;
use Wdc\Store\Presentation\Presenter\Restricted\Receipt\ReceiptKeys;
use Wdc\Store\Presentation\StoreApplication;
use Wdc\Store\Presentation\StorageKeys;

/**
 * Home of the restricted area: header (name, cart, exit), the two panels (catalog and purchase
 * history) and a slot where product, cart and receipt open.
 *
 * @extends AbstractCubePresenter<StoreApplication>
 */
final class HomePresenter extends AbstractCubePresenter
{
    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly HomeViewState $state;

    private readonly CubeViewSlot $contentSlot;

    private ?CubeViewSlot $ownerSlot = null;

    private ?CartManager $cart = null;

    private ?ProductsPanelPresenter $productsPanel = null;

    private ?PurchasesPanelPresenter $purchasesPanel = null;

    /** @var list<Registration> cart listeners, removed on release */
    private array $cartListeners = [];

    public function __construct(StoreApplication $app)
    {
        parent::__construct($app);
        $this->state = new HomeViewState();
        $this->contentSlot = new CubeViewSlot($this->setContentView(...));
    }

    public function release(): void
    {
        $this->state->contentView = null;
        $this->productsPanel?->release();
        $this->productsPanel = null;
        $this->state->productsPanelView = null;
        $this->purchasesPanel?->release();
        $this->purchasesPanel = null;
        $this->state->purchasesPanelView = null;

        $this->app->setCart(null);
        foreach ($this->cartListeners as $registration) {
            $registration->remove();
        }
        $this->cartListeners = [];

        parent::release();
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $subject = $this->app->subject();
        if ($subject === null) {
            (new LoginKeys($this->app, $intent))->go();

            return false;
        }

        if ($initialization || $this->view === null) {
            $this->view = (self::$createView ?? throw new LogicException('HomePresenter::$createView not configured'))($this);
            $this->state->nickName = $subject->nickName;

            $this->productsPanel = new ProductsPanelPresenter($this->app, $this);
            $this->state->productsPanelView = $this->productsPanel->initialize();
            $this->purchasesPanel = new PurchasesPanelPresenter($this->app, $this);
            $this->state->purchasesPanelView = $this->purchasesPanel->initialize();

            $this->cart = new CartManager();
            $this->cartListeners = [
                $this->cart->addCommitListener($this->onCartCommitted(...)),
                $this->cart->addChangeListener($this->onCartChanged(...)),
            ];
            $this->app->setCart($this->cart);
            $this->update();
        }

        $keys = new HomeKeys($this->app, $intent);
        $this->ownerSlot = $keys->ownerSlot;   // on every trip: the owner above decides the slot
        if ($this->ownerSlot === null) {
            return false;
        }
        $this->ownerSlot->setView($this->view);
        if ($deepest) {
            $this->setContentView(null);   // the home is the destination: show the panels
        } else {
            $keys->ownerSlot = $this->contentSlot;   // re-offers it, under the same name, to the level below
        }

        return true;
    }

    /** Derived state, recomputed right before the view is sent to the client. */
    public function commitComputedState(): void
    {
        $count = $this->cart?->itemCount() ?? 0;
        if ($this->state->cartItemCount !== $count) {
            $this->state->cartItemCount = $count;
            $this->update();
        }
    }

    public function onOpenProduct(?int $productId): void
    {
        try {
            if ($productId === null) {
                $this->alert(6, 'Product code is a required argument.');

                return;
            }
            $product = ProductKeys::of($this->app);   // the trip starts from the current state
            $product->productId = $productId;
            $product->go();
        } catch (ProductNotFoundException) {
            $this->alert(3, 'Product code not found.');
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError("Trying to go to product place to show productId={$productId}", $caught);
        }
    }

    /** A missing receipt (PurchaseNotFoundException) becomes error 5. */
    public function onOpenReceipt(?int $purchaseId): void
    {
        try {
            if ($purchaseId === null) {
                $this->alert(7, 'Receipt code is a required argument.');

                return;
            }
            $receipt = ReceiptKeys::of($this->app);
            $receipt->purchaseId = $purchaseId;
            $receipt->go();
        } catch (PurchaseNotFoundException) {
            $this->alert(5, 'Receipt code not found.');
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError("Trying to go to receipt place to show purchaseId={$purchaseId}", $caught);
        }
    }

    public function onOpenCart(): void
    {
        try {
            CartKeys::of($this->app)->go();
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Trying to go to cart place', $caught);
        }
    }

    public function onExit(): void
    {
        try {
            $this->cart?->clear();
            $this->app->setSubject(null);
            $this->setContentView(null);
            $storage = $this->app->clientPersistentStore()->secure();
            $storage->remove(StorageKeys::AUTH_TOKEN);
            Beans::find(AuthenticationService::class)?->logout($storage->get(StorageKeys::AUTH_REFRESH_TOKEN));
            LoginKeys::of($this->app)->go();
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Trying to go to login place', $caught);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('473dbdd7a36a', function (int $event, int $count, array $form): void {
            match ($event) {
                1 => $this->onExit(),
                2 => $this->onOpenCart(),
                default => Skeleton::unknownEvent($event),
            };
        });
    }

    private function onCartCommitted(): void
    {
        $this->productsPanel?->loadProducts();
        $this->purchasesPanel?->onPageChange(0);
    }

    private function onCartChanged(): void
    {
        $this->state->cartItemCount = $this->cart?->itemCount() ?? 0;
        $this->update();
    }

    private function alert(int $code, string $message): void
    {
        $this->state->errorCode = $code;
        $this->state->errorMessage = $message;
        $this->update();
        $this->app->logger()->warning("HomePresenter: {$message}");
    }

    private function setContentView(?CubeView $view): void
    {
        if ($this->state->contentView !== $view) {
            $this->state->contentView = $view;
            $this->update();
            if ($view === null) {   // back to the home: the purchase history may have changed
                $this->purchasesPanel?->loadPurchases();
            }
        }
    }
}
