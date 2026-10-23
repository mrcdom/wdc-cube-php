<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Cart;

use Closure;
use DateTimeImmutable;
use LogicException;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\CallbackRegistration;
use Wdc\Framework\Commons\Concurrent\Registration;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Store\Domain\Exception\InvalidCartItemException;
use Wdc\Store\Domain\Product\Product;
use Wdc\Store\Domain\PurchaseItem\PurchaseItem;
use Wdc\Store\Domain\Purchase\Purchase;
use Wdc\Store\Domain\Purchase\PurchaseRepository;
use Wdc\Store\Domain\User\User;
use Wdc\Store\Presentation\Presenter\Open\Login\Subject;
use Wdc\Store\Presentation\Presenter\Restricted\Products\ProductInfo;

/** The session's cart: items in memory until checkout, which becomes a Purchase in the database. */
final class CartManager
{
    /** @var list<CartItem> */
    private array $items = [];

    private int $listenerIdGen = 0;

    /** @var array<int, Closure(): mixed> */
    private array $commitListeners = [];

    /** @var array<int, Closure(): mixed> */
    private array $changeListeners = [];

    private readonly TransactionService $tx;

    private readonly PurchaseRepository $purchases;

    public function __construct()
    {
        $this->tx = Beans::of(TransactionService::class);
        $this->purchases = Beans::of(PurchaseRepository::class);
    }

    /**
     * Notified after each completed purchase. The returned Registration undoes the registration.
     *
     * @param Closure(): mixed $listener
     */
    public function addCommitListener(Closure $listener): Registration
    {
        $id = $this->listenerIdGen++;
        $this->commitListeners[$id] = $listener;

        return new CallbackRegistration(function () use ($id): void {
            unset($this->commitListeners[$id]);
        });
    }

    /**
     * Notified on every change to the cart's contents.
     *
     * @param Closure(): mixed $listener
     */
    public function addChangeListener(Closure $listener): Registration
    {
        $id = $this->listenerIdGen++;
        $this->changeListeners[$id] = $listener;

        return new CallbackRegistration(function () use ($id): void {
            unset($this->changeListeners[$id]);
        });
    }

    /**
     * A copy of the list (the items themselves are the same objects).
     *
     * @return list<CartItem>
     */
    public function cartItems(): array
    {
        return $this->items;   // arrays are copied when returned — the internal list does not change
    }

    /** Product already in the cart ⇒ adds to the quantity. */
    public function addProduct(ProductInfo $product, int $quantity): void
    {
        $existing = $this->find($product->id);
        if ($existing !== null) {
            $existing->quantity += $quantity;
        } else {
            $this->items[] = CartItem::create($product, $quantity);
        }
        // adding and increasing the quantity both notify the listeners
        $this->fire($this->changeListeners);
    }

    public function modifyProductQuantity(int $productId, int $quantity): bool
    {
        $item = $this->find($productId);
        if ($item === null) {
            return false;
        }
        $item->quantity = $quantity;
        $this->fire($this->changeListeners);

        return true;
    }

    public function removeProduct(int $productId): bool
    {
        foreach ($this->items as $i => $item) {
            if ($item->id === $productId) {
                array_splice($this->items, $i, 1);   // removes and reindexes (keeps the array a list)
                $this->fire($this->changeListeners);

                return true;
            }
        }

        return false;
    }

    /**
     * Checks out: saves the purchase and its items in one transaction, empties the cart and notifies.
     *
     * @return int the purchase id
     *
     * @throws InvalidCartItemException an item with a negative quantity
     */
    public function commit(Subject $subject): int
    {
        $purchaseId = $this->doPurchase($subject->id);
        $this->clear();
        $this->fire($this->changeListeners);
        $this->fire($this->commitListeners);

        return $purchaseId;
    }

    public function itemCount(): int
    {
        return array_sum(array_map(static fn (CartItem $i): int => $i->quantity, $this->items));
    }

    public function size(): int
    {
        return count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    private function doPurchase(int $userId): int
    {
        $items = [];
        foreach ($this->items as $cartItem) {
            if ($cartItem->quantity < 0) {
                throw new InvalidCartItemException();
            }
            $items[] = new PurchaseItem()
                ->withProduct(new Product()->withId($cartItem->id))
                ->withPrice($cartItem->price)
                ->withAmount($cartItem->quantity);
        }
        $purchase = new Purchase()
            ->withUser(new User()->withId($userId))
            ->withBuyDate(new DateTimeImmutable())
            ->withItems($items);

        // the presentation's only transaction boundary: purchase + items, all or nothing
        $this->tx->required(function () use ($purchase): void {
            if (!$this->purchases->insert($purchase)) {
                throw new LogicException('Record not inserted');
            }
        });

        return $purchase->id() ?? throw new LogicException('Purchase has no id after insert');
    }

    private function find(int $productId): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $productId) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<int, Closure(): mixed> $listeners */
    private function fire(array $listeners): void
    {
        foreach ($listeners as $listener) {   // iterates a copy: a listener may remove itself
            $listener();
        }
    }
}
