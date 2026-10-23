<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Cart;

use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomeKeys;
use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptKeys;
use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Concurrent\Registration;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Framework\Domain\Exception\OfflineException;
use Wdc\Shopping\Domain\Exception\InvalidCartItemException;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * @extends AbstractCubePresenter<ShoppingApplication>
 */
final class CartPresenter extends AbstractCubePresenter
{
    /** Error notices disappear on their own after this delay. */
    public const float ERROR_CLEAR_DELAY_SECONDS = 3.0;

    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly CartViewState $state;

    private readonly CartManager $cart;

    private ?CubeViewSlot $ownerSlot = null;

    private ?Registration $pendingErrorClear = null;

    public function __construct(ShoppingApplication $app)
    {
        parent::__construct($app);
        $this->state = new CartViewState();
        // the home (one level up) creates the cart before this presenter is born
        $this->cart = $app->cart() ?? throw new LogicException('Cart not initialized by the home');
    }

    public function release(): void
    {
        $this->pendingErrorClear?->remove();
        $this->pendingErrorClear = null;
        parent::release();
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $this->state->items = $this->cart->cartItems();
        if ($initialization) {
            $this->view = (self::$createView ?? throw new LogicException('CartPresenter::$createView not configured'))($this);
        }
        $this->ownerSlot = (new CartKeys($this->app, $intent))->ownerSlot;   // on every trip: the owner above decides the slot
        $this->ownerSlot?->setView($this->view);

        return true;
    }

    public function onModifyQuantity(?int $productId, ?int $quantity): void
    {
        try {
            if ($productId === null) {
                $this->alert(5, 'Malformed product code.');
            } elseif ($quantity === null) {
                $this->alert(6, 'Malformed quantity value.');
            } elseif ($quantity < 1) {
                $this->alert(1, 'There must be at least one item in the cart to complete a purchase');
            } elseif (!$this->cart->modifyProductQuantity($productId, $quantity)) {
                $this->alert(2, 'Product not found in the database.');
            } else {
                $this->state->errorCode = 0;
                $this->state->errorMessage = null;
                $this->state->items = $this->cart->cartItems();
                $this->update();
            }
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Modifying a product quantity', $caught);
        }
    }

    public function onRemoveProduct(?int $productId): void
    {
        try {
            if ($productId === null) {
                $this->alert(5, 'Malformed product code.');

                return;
            }
            if ($this->cart->removeProduct($productId)) {
                if ($this->cart->size() === 0) {
                    HomeKeys::of($this->app)->go();
                } else {
                    $this->state->items = $this->cart->cartItems();
                    $this->update();
                }
            }
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Removing a product', $caught);
        }
    }

    public function onBuy(): void
    {
        try {
            if ($this->cart->size() === 0) {
                $this->alert(3, 'Some products have a quantity of less than one item. Unable to purchase.');

                return;
            }
            $subject = $this->app->subject() ?? throw new LogicException('Purchase without a logged-in user');
            $purchaseId = $this->cart->commit($subject);

            $receipt = ReceiptKeys::of($this->app);   // the trip starts from the current state
            $receipt->purchaseId = $purchaseId;
            $receipt->purchaseMade = true;
            $receipt->go();
        } catch (InvalidCartItemException $caught) {
            $this->alert(1, 'There must be at least one item in the cart to complete a purchase');
            $this->app->logger()->error('onBuy', ['exception' => $caught]);
        } catch (OfflineException $caught) {
            $this->alert(4, 'The database is currently offline. Please wait a moment and try again.');
            $this->app->logger()->error('onBuy', ['exception' => $caught]);
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Buying a product', $caught);
        }
    }

    public function onOpenProducts(): void
    {
        try {
            HomeKeys::of($this->app)->go();
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Going to root restricted place', $caught);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('7eb485e5f843', function (int $event, int $count, array $form): void {
            match ($event) {
                1 => $this->onBuy(),
                2 => $this->onRemoveProduct(Coerce::asInt($form['p.productId'] ?? null)),
                3 => $this->onOpenProducts(),
                4 => $this->onModifyQuantity(Coerce::asInt($form['p.productId'] ?? null), Coerce::asInt($form['p.quantity'] ?? null)),
                default => Skeleton::unknownEvent($event),
            };
        });
    }

    /**
     * Shows the error and schedules clearing it. On the server the scheduler is the event loop: when
     * the time is up, the state changes and the screen is updated without the user doing anything.
     *
     * (The messages for codes 1 and 3 look swapped; kept as is, the tests check the code.)
     */
    private function alert(int $code, string $message): void
    {
        $this->state->errorCode = $code;
        $this->state->errorMessage = $message;
        $this->update();
        $this->app->logger()->warning("CartPresenter: {$message}");

        $this->pendingErrorClear?->remove();
        $this->pendingErrorClear = $this->app->scheduler()->schedule(function (): void {
            $this->pendingErrorClear = null;
            $this->state->errorCode = 0;
            $this->state->errorMessage = null;
            $this->update();
        }, self::ERROR_CLEAR_DELAY_SECONDS);
    }
}
