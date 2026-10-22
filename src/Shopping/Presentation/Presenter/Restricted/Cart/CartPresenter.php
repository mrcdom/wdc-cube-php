<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Cart;

use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptKeys;
use Wdc\Shopping\Presentation\Presenter\RootKeys;
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
use Wdc\Shopping\Presentation\Routes;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * @extends AbstractCubePresenter<ShoppingApplication>
 */
final class CartPresenter extends AbstractCubePresenter
{
    /** Avisos de erro somem sozinhos depois deste tempo. */
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
        // a home (um nível acima) cria o carrinho antes de este presenter nascer
        $this->cart = $app->cart() ?? throw new LogicException('Carrinho não inicializado pela home');
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
            $this->view = (self::$createView ?? throw new LogicException('CartPresenter::$createView não configurado'))($this);
        }
        $this->ownerSlot = (new RootKeys($this->app, $intent))->ownerSlot;   // a cada viagem: quem decide o slot é o dono acima
        $this->ownerSlot?->setView($this->view);

        return true;
    }

    public function onModifyQuantity(?int $productId, ?int $quantity): void
    {
        try {
            if ($productId === null) {
                $this->alert(5, 'Código do produto mal formado.');
            } elseif ($quantity === null) {
                $this->alert(6, 'Valor da quantiade está mal formado.');
            } elseif ($quantity < 1) {
                $this->alert(1, 'Deve existir pelo menos um item no carrinhro para se efetivar uma compra');
            } elseif (!$this->cart->modifyProductQuantity($productId, $quantity)) {
                $this->alert(2, 'Produdo não localizado na base dados.');
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
                $this->alert(5, 'Código do produto mal formado.');

                return;
            }
            if ($this->cart->removeProduct($productId)) {
                if ($this->cart->size() === 0) {
                    Routes::home($this->app);
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
                $this->alert(3, 'Existem produtos com menos de um item na quantidade. Impossível comprar.');

                return;
            }
            $subject = $this->app->subject() ?? throw new LogicException('Compra sem usuário logado');
            $purchaseId = $this->cart->commit($subject);

            $receipt = ReceiptKeys::of($this->app);   // a viagem parte do estado atual
            $receipt->purchaseId = $purchaseId;
            $receipt->purchaseMade = true;
            $receipt->go();
        } catch (InvalidCartItemException $caught) {
            $this->alert(1, 'Deve existir pelo menos um item no carrinhro para se efetivar uma compra');
            $this->app->logger()->error('onBuy', ['exception' => $caught]);
        } catch (OfflineException $caught) {
            $this->alert(4, 'O banco de dados encontra-se fora do ar no momento. Aguarde alguns instantes e tente novamente.');
            $this->app->logger()->error('onBuy', ['exception' => $caught]);
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Buying a product', $caught);
        }
    }

    public function onOpenProducts(): void
    {
        try {
            Routes::home($this->app);
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Going to root restricted place', $caught);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('7eb485e5f843', function (int $event, int $qtde, array $form): void {
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
     * Mostra o erro e agenda a limpeza. No servidor o agendador é o event loop: quando o tempo
     * passa, o estado muda e a tela é atualizada sem o usuário ter feito nada.
     *
     * (As mensagens dos códigos 1 e 3 parecem trocadas; mantidas, os testes olham o código.)
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
