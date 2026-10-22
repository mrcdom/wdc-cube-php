<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation;

use PHPUnit\Framework\Attributes\Test;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Tests\Shopping\Presentation\Mock\CartViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\ProductViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\ReceiptViewMock;

/** Fluxo de compra pelos presenters: visualizar produto (existente ou não) e comprar até o recibo. */
final class ShoppingWorkflowTest extends PresentationTestCase
{
    private function home(): HomeViewMock
    {
        return HomeViewMock::cast($this->app->rootView()->state->contentView);
    }

    #[Test]
    public function visualizaProdutoInexistente(): void
    {
        $this->login()->presenter->onOpenProduct(PHP_INT_MIN);

        // produto inexistente ⇒ rollback da navegação; a home continua e mostra o erro
        self::assertSame(3, $this->home()->state->errorCode, 'O código de erro deve indicar produto não existe');
        self::assertNull($this->home()->state->contentView);
    }

    #[Test]
    public function visualizaProduto(): void
    {
        $this->login()->presenter->onOpenProduct(Seed::PEN_DRIVE2GB_ID);

        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertNotNull($product->state->product, 'Produto deve ter sido selecionado');
    }

    #[Test]
    public function comprarProduto(): void
    {
        $home = $this->login();

        $home->presenter->onOpenProduct(Seed::PEN_DRIVE2GB_ID);
        $product = ProductViewMock::cast($home->state->contentView);
        self::assertSame(Seed::PEN_DRIVE2GB_ID, $product->state->product?->id);

        $product->presenter->onAddToCart(1);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertSame(0, $cart->state->errorCode, 'Não deve haver indicação de erros');
        self::assertCount(1, $cart->state->items);
        self::assertSame(1, $cart->state->items[0]->quantity);
        self::assertSame(Seed::PEN_DRIVE2GB_ID, $cart->state->items[0]->id);

        $cart->presenter->onModifyQuantity(Seed::PEN_DRIVE2GB_ID, 0);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertSame(1, $cart->state->errorCode, 'Indicação de quantidade inválida');
        $cart->state->errorCode = 0;

        $cart->presenter->onModifyQuantity(Seed::PEN_DRIVE2GB_ID, 2);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertSame(0, $cart->state->errorCode, 'Tem que funcionar sem erro');
        self::assertSame(2, $cart->state->items[0]->quantity);

        $cart->presenter->onModifyQuantity(PHP_INT_MIN, 2);
        self::assertSame(2, $cart->state->errorCode, 'Produto não encontrado');
        $cart->state->errorCode = 0;

        $cart->presenter->onOpenProducts();
        $this->home()->presenter->onOpenProduct(Seed::BOLA_WILSON_ID);
        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertSame(Seed::BOLA_WILSON_ID, $product->state->product?->id);

        $product->presenter->onOpenProducts();
        $this->home()->presenter->onOpenProduct(Seed::FITA_VEDA_ROSCA_ID);
        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertSame(Seed::FITA_VEDA_ROSCA_ID, $product->state->product?->id);

        $product->presenter->onAddToCart(1);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertCount(2, $cart->state->items);
        self::assertSame(1, $cart->state->items[1]->quantity);

        $cart->presenter->onBuy();
        $receipt = ReceiptViewMock::cast($this->home()->state->contentView);
        self::assertTrue($receipt->state->notifySuccess, 'Tem que estar marcado como novo recibo');
        self::assertNotNull($receipt->state->receipt);
        self::assertCount(2, $receipt->state->receipt->items);

        $receipt->presenter->onOpenProducts();
        self::assertNull($this->home()->state->contentView, 'A home deveria mostrar o conteúdo padrão');
    }
}
