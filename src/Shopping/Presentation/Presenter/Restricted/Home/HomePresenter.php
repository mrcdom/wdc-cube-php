<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home;

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
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Presentation\Exception\ProductNotFoundException;
use Wdc\Shopping\Presentation\Exception\PurchaseNotFoundException;
use Wdc\Shopping\Presentation\Presenter\Open\Login\LoginKeys;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartKeys;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartManager;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Products\ProductsPanelPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductKeys;
use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptKeys;
use Wdc\Shopping\Presentation\ShoppingApplication;
use Wdc\Shopping\Presentation\StorageKeys;

/**
 * Home da área restrita: cabeçalho (nome, carrinho, sair), os dois painéis (vitrine e extrato)
 * e um slot onde abrem produto, carrinho e recibo.
 *
 * @extends AbstractCubePresenter<ShoppingApplication>
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

    /** @var list<Registration> ouvintes do carrinho, desfeitos no release */
    private array $cartListeners = [];

    public function __construct(ShoppingApplication $app)
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
            $this->view = (self::$createView ?? throw new LogicException('HomePresenter::$createView não configurado'))($this);
            $this->state->nickName = $subject->nickName;

            $this->productsPanel = new ProductsPanelPresenter($this->app, $this);
            $this->state->productsPanelView = $this->productsPanel->initialize();
            $this->purchasesPanel = new PurchasesPanelPresenter($this->app, $this);
            $this->state->purchasesPanelView = $this->purchasesPanel->initialize();

            $this->cart = new CartManager(Beans::of(TransactionService::class), Beans::of(PurchaseRepository::class));
            $this->cartListeners = [
                $this->cart->addCommitListener($this->onCartCommitted(...)),
                $this->cart->addChangeListener($this->onCartChanged(...)),
            ];
            $this->app->setCart($this->cart);
            $this->update();
        }

        $keys = new HomeKeys($this->app, $intent);
        $this->ownerSlot = $keys->ownerSlot;   // a cada viagem: quem decide o slot é o dono acima
        if ($this->ownerSlot === null) {
            return false;
        }
        $this->ownerSlot->setView($this->view);
        if ($deepest) {
            $this->setContentView(null);   // a home é o destino: mostra os painéis
        } else {
            $keys->ownerSlot = $this->contentSlot;   // reoferece, com o mesmo nome, ao nível de baixo
        }

        return true;
    }

    /** Estado derivado, recalculado logo antes de a view ser enviada ao cliente. */
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
                $this->alert(6, 'Código do produto é um argumento obrigatório.');

                return;
            }
            $product = ProductKeys::of($this->app);   // a viagem parte do estado atual
            $product->productId = $productId;
            $product->go();
        } catch (ProductNotFoundException) {
            $this->alert(3, 'Código do produto não localizado.');
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError("Trying to go to product place to show productId={$productId}", $caught);
        }
    }

    /** Recibo inexistente (PurchaseNotFoundException) vira o erro 5. */
    public function onOpenReceipt(?int $purchaseId): void
    {
        try {
            if ($purchaseId === null) {
                $this->alert(7, 'Código do recibo é um argumento obrigatório.');

                return;
            }
            $receipt = ReceiptKeys::of($this->app);
            $receipt->purchaseId = $purchaseId;
            $receipt->go();
        } catch (PurchaseNotFoundException) {
            $this->alert(5, 'Código do recibo não localizado.');
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
        return new Skeleton('473dbdd7a36a', function (int $event, int $qtde, array $form): void {
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
            if ($view === null) {   // voltou para a home: o extrato pode ter mudado
                $this->purchasesPanel?->loadPurchases();
            }
        }
    }
}
