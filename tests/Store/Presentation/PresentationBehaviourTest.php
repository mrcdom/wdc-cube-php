<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Presentation;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Storage\InMemoryClientStorage;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Store\Persistence\StoreSchema as Seed;
use Wdc\Store\Presentation\Place;
use Wdc\Store\Presentation\Presenter\Restricted\Cart\CartPresenter;
use Wdc\Store\Presentation\Routes;
use Wdc\Store\Presentation\StoreContext;
use Wdc\Store\Presentation\StorageKeys;
use Wdc\Tests\Store\Presentation\Mock\CartViewMock;
use Wdc\Tests\Store\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Store\Presentation\Mock\LoginViewMock;
use Wdc\Tests\Store\Presentation\Mock\ProductViewMock;
use Wdc\Tests\Store\Presentation\Mock\ReceiptViewMock;
use Wdc\Tests\Store\Presentation\Mock\StoreApplicationMock;

/** Presentation behaviours beyond the main flow (counters, errors, edge cases). */
final class PresentationBehaviourTest extends PresentationTestCase
{
    private function home(): HomeViewMock
    {
        return HomeViewMock::cast($this->app->rootView()->state->contentView);
    }

    #[Test]
    public function guards_sendAnonymousUsersToLogin_andLoggedUsersToHome(): void
    {
        self::assertFalse($this->app->go('cart'), 'restricted area without login: navigation is redirected');
        self::assertSame(Place::LOGIN, $this->app->lastPlace());
        LoginViewMock::cast($this->app->rootView()->state->contentView);

        $this->login();
        $this->app->go('open');
        self::assertSame(Place::HOME, $this->app->lastPlace(), 'a logged-in user does not go back to the public area');
    }

    #[Test]
    public function goByName_parsesPlaceAndParameters(): void
    {
        $this->login();

        $this->app->go('product?pr-id=' . Seed::WILSON_BALL_ID);

        self::assertSame(Seed::WILSON_BALL_ID, ProductViewMock::cast($this->home()->state->contentView)->state->product?->id);
        self::assertSame('product?rt-user=0&pr-id=1', (string) $this->app->newIntent(), 'the URL comes from the presenters');
    }

    #[Test]
    public function login_storesThePersistentToken_andANewSessionLogsInAutomatically(): void
    {
        $this->login();
        $token = $this->app->clientPersistentStore()->secure()->get(StorageKeys::AUTH_TOKEN);
        self::assertNotNull($token);

        // another session (another tab, restarted server...) with the same browser storage
        $storage = new InMemoryClientStorage();
        $storage->secure()->set(StorageKeys::AUTH_TOKEN, $token);
        $other = new StoreApplicationMock($this->context, $storage);
        Routes::root($other);

        self::assertSame(Place::HOME, $other->lastPlace(), 'auto-login through the persistent token');
        self::assertSame('John Smith', HomeViewMock::cast($other->rootView()->state->contentView)->state->nickName);
        $other->release();
    }

    #[Test]
    public function invalidPersistentToken_isDiscarded(): void
    {
        $storage = new InMemoryClientStorage();
        $storage->secure()->set(StorageKeys::AUTH_TOKEN, 'garbage');
        $other = new StoreApplicationMock($this->context, $storage);
        Routes::root($other);

        self::assertSame(Place::LOGIN, $other->lastPlace());
        self::assertNull($storage->secure()->get(StorageKeys::AUTH_TOKEN));
        $other->release();
    }

    #[Test]
    public function exit_clearsSessionAndToken_andGoesToLogin(): void
    {
        $this->login()->presenter->onExit();

        self::assertNull($this->app->subject());
        self::assertNull($this->app->clientPersistentStore()->secure()->get(StorageKeys::AUTH_TOKEN));
        LoginViewMock::cast($this->app->rootView()->state->contentView);
    }

    #[Test]
    public function cartErrors_clearThemselvesAfterThreeSeconds(): void
    {
        $this->login()->presenter->onOpenProduct(Seed::COFFEE_MAKER_ID);
        ProductViewMock::cast($this->home()->state->contentView)->presenter->onAddToCart(1);
        $cart = CartViewMock::cast($this->home()->state->contentView);

        $cart->presenter->onModifyQuantity(Seed::COFFEE_MAKER_ID, 0);
        self::assertSame(1, $cart->state->errorCode);

        $this->scheduler->advance(CartPresenter::ERROR_CLEAR_DELAY_SECONDS - 0.1);
        self::assertSame(1, $cart->state->errorCode, 'not yet');
        $this->scheduler->advance(0.2);
        self::assertSame(0, $cart->state->errorCode);
        self::assertNull($cart->state->errorMessage);
    }

    #[Test]
    public function cartItemCount_followsTheCart_includingQuantityIncrements(): void
    {
        $this->login();
        foreach ([2, 3] as $quantity) {
            $this->home()->presenter->onOpenProduct(Seed::FLASH_DRIVE2GB_ID);
            ProductViewMock::cast($this->home()->state->contentView)->presenter->onAddToCart($quantity);
        }

        self::assertSame(5, $this->home()->state->cartItemCount, 'adding to the quantity notifies too, not just adding an item');
    }

    #[Test]
    public function receiptOfAnotherPurchaseId_thatDoesNotExist_showsError5(): void
    {
        $this->login()->presenter->onOpenReceipt(PHP_INT_MAX);

        self::assertSame(5, $this->home()->state->errorCode, 'a nonexistent purchase ends in error 5');
    }

    #[Test]
    public function openingAReceiptFromTheStatement_doesNotNotifySuccess(): void
    {
        $this->login()->presenter->onOpenReceipt(Seed::ADMIN_SECOND_PURCHASE_ID);

        $receipt = ReceiptViewMock::cast($this->home()->state->contentView);
        self::assertFalse($receipt->state->notifySuccess);
        self::assertSame([1, 2], array_map(static fn ($i) => $i->id, $receipt->state->receipt->items ?? []), 'items by id');
    }

    #[Test]
    public function skeletons_dispatchEventsLikeTheRemoteClient(): void
    {
        Routes::login($this->app);
        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        $login->presenter->skeleton()->syncState(['userName' => 'adm']);
        self::assertSame('adm', $login->state->userName, 'fields without "p." sync the state');

        // the password arrives encrypted with the session key (Base64 in the mock)
        $login->presenter->skeleton()->submit(1, 1, ['p.userName' => 'admin', 'p.password' => base64_encode('admin')]);
        $home = $this->home();

        $home->productsPanel()->presenter->skeleton()->submit(1, 1, ['p.productId' => (string) Seed::THREAD_SEAL_TAPE_ID]);
        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertSame(Seed::THREAD_SEAL_TAPE_ID, $product->state->product?->id, 'the number arrives as JSON text');

        $product->presenter->skeleton()->submit(2, 1, ['p.quantity' => 2]);
        self::assertSame(2, CartViewMock::cast($this->home()->state->contentView)->state->items[0]->quantity ?? null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('eventCode(99) not handled');
        $home->presenter->skeleton()->submit(99, 1, []);
    }

    #[Test]
    public function withoutAuthenticationService_loginChecksThePasswordDirectly(): void
    {
        Beans::set(AuthenticationService::class, null);
        $app = new StoreApplicationMock(new StoreContext($this->scheduler));
        Routes::login($app);
        LoginViewMock::cast($app->rootView()->state->contentView)->presenter->onEnter('john', 'john');

        self::assertSame(Place::HOME, $app->lastPlace());
        self::assertNull($app->clientPersistentStore()->secure()->get(StorageKeys::AUTH_TOKEN), 'no "remember me"');
        $app->release();
    }
}
