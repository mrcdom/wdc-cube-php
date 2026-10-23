<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Products;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Framework\Domain\Exception\OfflineException;
use Wdc\Shopping\Presentation\Exception\ProductNotFoundException;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartKeys;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomeKeys;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * Detalhe do produto (`product?pr-id=N`).
 *
 * Produto inexistente ⇒ ProductNotFoundException dentro do applyParameters ⇒ a navegação
 * desfaz tudo (rollback) e relança; quem pediu (a home) mostra o erro.
 *
 * @extends AbstractCubePresenter<ShoppingApplication>
 */
final class ProductPresenter extends AbstractCubePresenter
{
    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly ProductViewState $state;

    private readonly ProductService $productService;

    private ?CubeViewSlot $ownerSlot = null;

    public function __construct(ShoppingApplication $app)
    {
        parent::__construct($app);
        $this->state = new ProductViewState();
        $this->productService = new ProductService();
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $keys = new ProductKeys($this->app, $intent);
        $oldId = $this->state->product?->id;
        $newId = $keys->productId ?? throw new ProductNotFoundException();   // ausente ou inválido

        if ($this->state->product === null || $newId !== $oldId) {
            $this->state->product = $this->productService->loadProductById($newId);
            $this->update();
        }
        if ($initialization) {
            $this->state->errorCode = 0;
            $this->state->errorMessage = null;
            $this->view = (self::$createView ?? throw new LogicException('ProductPresenter::$createView não configurado'))($this);
            $this->update();
        }
        $this->ownerSlot = $keys->ownerSlot;   // a cada viagem: quem decide o slot é o dono acima
        $this->ownerSlot?->setView($this->view);

        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        if ($this->state->product !== null) {
            $keys = new ProductKeys($this->app, $intent);
            $keys->productId = $this->state->product->id;
        }
    }

    public function onAddToCart(?int $quantity): void
    {
        try {
            if ($quantity === null) {
                $this->alert(3, 'Codificação errada da quantidade.');

                return;
            }
            if ($quantity < 1) {
                $this->alert(2, 'A quantidade de itens no carrinho deve ser maior ou igual a 1 (um).');

                return;
            }
            $product = $this->state->product ?? throw new LogicException('Sem produto carregado');
            $cart = $this->app->cart() ?? throw new LogicException('Carrinho não inicializado');
            $cart->addProduct($product, $quantity);
            CartKeys::of($this->app)->go();
        } catch (OfflineException $caught) {
            $this->alert(1, 'Carrinho não acessível. Aguarde alguns instantes e tente novamente.');
            $this->app->logger()->error('onAddToCart', ['exception' => $caught]);
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Adding an item to cart', $caught);
        }
    }

    public function onOpenProducts(): void
    {
        try {
            HomeKeys::of($this->app)->go();
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Going to home of restricted place', $caught);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('48b693f67410', function (int $event, int $qtde, array $form): void {
            match ($event) {
                1 => $this->onOpenProducts(),
                2 => $this->onAddToCart(Coerce::asInt($form['p.quantity'] ?? null)),
                default => Skeleton::unknownEvent($event),
            };
        });
    }

    private function alert(int $code, string $message): void
    {
        $this->state->errorCode = $code;
        $this->state->errorMessage = $message;
        $this->update();
        $this->app->logger()->warning("ProductPresenter: {$message}");
    }
}
