<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Presentation;

use PHPUnit\Framework\Attributes\Test;
use Wdc\Store\Persistence\StoreSchema as Seed;
use Wdc\Tests\Store\Presentation\Mock\CartViewMock;
use Wdc\Tests\Store\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Store\Presentation\Mock\ProductViewMock;
use Wdc\Tests\Store\Presentation\Mock\ReceiptViewMock;

/** Purchase flow through the presenters: view a product (existing or not) and buy through to the receipt. */
final class StoreWorkflowTest extends PresentationTestCase
{
    private function home(): HomeViewMock
    {
        return HomeViewMock::cast($this->app->rootView()->state->contentView);
    }

    #[Test]
    public function viewNonexistentProduct(): void
    {
        $this->login()->presenter->onOpenProduct(PHP_INT_MIN);

        // nonexistent product ⇒ navigation rollback; the home stays and shows the error
        self::assertSame(3, $this->home()->state->errorCode, 'The error code must indicate the product does not exist');
        self::assertNull($this->home()->state->contentView);
    }

    #[Test]
    public function viewProduct(): void
    {
        $this->login()->presenter->onOpenProduct(Seed::FLASH_DRIVE2GB_ID);

        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertNotNull($product->state->product, 'Product should have been selected');
    }

    #[Test]
    public function buyProduct(): void
    {
        $home = $this->login();

        $home->presenter->onOpenProduct(Seed::FLASH_DRIVE2GB_ID);
        $product = ProductViewMock::cast($home->state->contentView);
        self::assertSame(Seed::FLASH_DRIVE2GB_ID, $product->state->product?->id);

        $product->presenter->onAddToCart(1);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertSame(0, $cart->state->errorCode, 'There should be no error indication');
        self::assertCount(1, $cart->state->items);
        self::assertSame(1, $cart->state->items[0]->quantity);
        self::assertSame(Seed::FLASH_DRIVE2GB_ID, $cart->state->items[0]->id);

        $cart->presenter->onModifyQuantity(Seed::FLASH_DRIVE2GB_ID, 0);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertSame(1, $cart->state->errorCode, 'Invalid quantity indication');
        $cart->state->errorCode = 0;

        $cart->presenter->onModifyQuantity(Seed::FLASH_DRIVE2GB_ID, 2);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertSame(0, $cart->state->errorCode, 'Must work without error');
        self::assertSame(2, $cart->state->items[0]->quantity);

        $cart->presenter->onModifyQuantity(PHP_INT_MIN, 2);
        self::assertSame(2, $cart->state->errorCode, 'Product not found');
        $cart->state->errorCode = 0;

        $cart->presenter->onOpenProducts();
        $this->home()->presenter->onOpenProduct(Seed::WILSON_BALL_ID);
        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertSame(Seed::WILSON_BALL_ID, $product->state->product?->id);

        $product->presenter->onOpenProducts();
        $this->home()->presenter->onOpenProduct(Seed::THREAD_SEAL_TAPE_ID);
        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertSame(Seed::THREAD_SEAL_TAPE_ID, $product->state->product?->id);

        $product->presenter->onAddToCart(1);
        $cart = CartViewMock::cast($this->home()->state->contentView);
        self::assertCount(2, $cart->state->items);
        self::assertSame(1, $cart->state->items[1]->quantity);

        $cart->presenter->onBuy();
        $receipt = ReceiptViewMock::cast($this->home()->state->contentView);
        self::assertTrue($receipt->state->notifySuccess, 'Must be flagged as a new receipt');
        self::assertNotNull($receipt->state->receipt);
        self::assertCount(2, $receipt->state->receipt->items);

        $receipt->presenter->onOpenProducts();
        self::assertNull($this->home()->state->contentView, 'The home should show the default content');
    }
}
