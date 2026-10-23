<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Cart;

use Closure;
use DateTimeImmutable;
use LogicException;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\CallbackRegistration;
use Wdc\Framework\Commons\Concurrent\Registration;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Shopping\Domain\Exception\InvalidCartItemException;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Presentation\Presenter\Open\Login\Subject;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductInfo;

/** O carrinho da sessão: itens em memória até a compra, que vira uma Purchase no banco. */
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
     * Avisado depois de cada compra fechada. A Registration devolvida desfaz o registro.
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
     * Avisado a cada mudança no conteúdo do carrinho.
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
     * Cópia da lista (os itens em si são os mesmos objetos).
     *
     * @return list<CartItem>
     */
    public function cartItems(): array
    {
        return $this->items;   // arrays são copiados ao sair da função — a lista interna não muda
    }

    /** Produto já no carrinho ⇒ soma a quantidade. */
    public function addProduct(ProductInfo $product, int $quantity): void
    {
        $existing = $this->find($product->id);
        if ($existing !== null) {
            $existing->quantity += $quantity;
        } else {
            $this->items[] = CartItem::create($product, $quantity);
        }
        // incluir e somar quantidade avisam os ouvintes
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
                array_splice($this->items, $i, 1);   // remove e reindexa (mantém a lista como list)
                $this->fire($this->changeListeners);

                return true;
            }
        }

        return false;
    }

    /**
     * Fecha a compra: grava compra e itens numa transação, esvazia o carrinho e avisa.
     *
     * @return int id da compra
     *
     * @throws InvalidCartItemException item com quantidade negativa
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

        // a única fronteira de transação da apresentação: compra + itens, tudo ou nada
        $this->tx->required(function () use ($purchase): void {
            if (!$this->purchases->insert($purchase)) {
                throw new LogicException('Record not inserted');
            }
        });

        return $purchase->id() ?? throw new LogicException('Compra sem id depois do insert');
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
        foreach ($listeners as $listener) {   // itera uma cópia: um ouvinte pode se remover
            $listener();
        }
    }
}
