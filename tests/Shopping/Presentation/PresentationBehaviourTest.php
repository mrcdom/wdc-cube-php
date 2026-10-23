<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Storage\InMemoryClientStorage;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartPresenter;
use Wdc\Shopping\Presentation\Routes;
use Wdc\Shopping\Presentation\ShoppingContext;
use Wdc\Shopping\Presentation\StorageKeys;
use Wdc\Tests\Shopping\Presentation\Mock\CartViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\LoginViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\ProductViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\ReceiptViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\ShoppingApplicationMock;

/** Comportamentos de apresentação além do fluxo principal (contadores, erros, casos de borda). */
final class PresentationBehaviourTest extends PresentationTestCase
{
    private function home(): HomeViewMock
    {
        return HomeViewMock::cast($this->app->rootView()->state->contentView);
    }

    #[Test]
    public function guards_sendAnonymousUsersToLogin_andLoggedUsersToHome(): void
    {
        self::assertFalse($this->app->go('cart'), 'área restrita sem login: a navegação é desviada');
        self::assertSame(Place::LOGIN, $this->app->lastPlace());
        LoginViewMock::cast($this->app->rootView()->state->contentView);

        $this->login();
        $this->app->go('open');
        self::assertSame(Place::HOME, $this->app->lastPlace(), 'logado não volta para a área pública');
    }

    #[Test]
    public function goByName_parsesPlaceAndParameters(): void
    {
        $this->login();

        $this->app->go('product?pr-id=' . Seed::BOLA_WILSON_ID);

        self::assertSame(Seed::BOLA_WILSON_ID, ProductViewMock::cast($this->home()->state->contentView)->state->product?->id);
        self::assertSame('product?rt-user=0&pr-id=1', (string) $this->app->newIntent(), 'a URL vem dos presenters');
    }

    #[Test]
    public function login_storesThePersistentToken_andANewSessionLogsInAutomatically(): void
    {
        $this->login();
        $token = $this->app->clientPersistentStore()->secure()->get(StorageKeys::AUTH_TOKEN);
        self::assertNotNull($token);

        // outra sessão (outra aba, servidor reiniciado...) com o mesmo armazenamento do browser
        $storage = new InMemoryClientStorage();
        $storage->secure()->set(StorageKeys::AUTH_TOKEN, $token);
        $other = new ShoppingApplicationMock($this->context, $storage);
        Routes::root($other);

        self::assertSame(Place::HOME, $other->lastPlace(), 'auto-login pelo token persistente');
        self::assertSame('João da Silva', HomeViewMock::cast($other->rootView()->state->contentView)->state->nickName);
        $other->release();
    }

    #[Test]
    public function invalidPersistentToken_isDiscarded(): void
    {
        $storage = new InMemoryClientStorage();
        $storage->secure()->set(StorageKeys::AUTH_TOKEN, 'lixo');
        $other = new ShoppingApplicationMock($this->context, $storage);
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
        $this->login()->presenter->onOpenProduct(Seed::CAFETEIRA_ID);
        ProductViewMock::cast($this->home()->state->contentView)->presenter->onAddToCart(1);
        $cart = CartViewMock::cast($this->home()->state->contentView);

        $cart->presenter->onModifyQuantity(Seed::CAFETEIRA_ID, 0);
        self::assertSame(1, $cart->state->errorCode);

        $this->scheduler->advance(CartPresenter::ERROR_CLEAR_DELAY_SECONDS - 0.1);
        self::assertSame(1, $cart->state->errorCode, 'ainda não');
        $this->scheduler->advance(0.2);
        self::assertSame(0, $cart->state->errorCode);
        self::assertNull($cart->state->errorMessage);
    }

    #[Test]
    public function cartItemCount_followsTheCart_includingQuantityIncrements(): void
    {
        $this->login();
        foreach ([2, 3] as $quantity) {
            $this->home()->presenter->onOpenProduct(Seed::PEN_DRIVE2GB_ID);
            ProductViewMock::cast($this->home()->state->contentView)->presenter->onAddToCart($quantity);
        }

        self::assertSame(5, $this->home()->state->cartItemCount, 'somar quantidade também avisa, não só a inclusão');
    }

    #[Test]
    public function receiptOfAnotherPurchaseId_thatDoesNotExist_showsError5(): void
    {
        $this->login()->presenter->onOpenReceipt(PHP_INT_MAX);

        self::assertSame(5, $this->home()->state->errorCode, 'compra inexistente cai no erro 5');
    }

    #[Test]
    public function openingAReceiptFromTheStatement_doesNotNotifySuccess(): void
    {
        $this->login()->presenter->onOpenReceipt(Seed::ADMIN_SECOND_PURCHASE_ID);

        $receipt = ReceiptViewMock::cast($this->home()->state->contentView);
        self::assertFalse($receipt->state->notifySuccess);
        self::assertSame([1, 2], array_map(static fn ($i) => $i->id, $receipt->state->receipt->items ?? []), 'itens por id');
    }

    #[Test]
    public function skeletons_dispatchEventsLikeTheRemoteClient(): void
    {
        Routes::login($this->app);
        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        $login->presenter->skeleton()->syncState(['userName' => 'adm']);
        self::assertSame('adm', $login->state->userName, 'campos sem "p." sincronizam o estado');

        // a senha chega cifrada com a chave da sessão (no mock, Base64)
        $login->presenter->skeleton()->submit(1, 1, ['p.userName' => 'admin', 'p.password' => base64_encode('admin')]);
        $home = $this->home();

        $home->productsPanel()->presenter->skeleton()->submit(1, 1, ['p.productId' => (string) Seed::FITA_VEDA_ROSCA_ID]);
        $product = ProductViewMock::cast($this->home()->state->contentView);
        self::assertSame(Seed::FITA_VEDA_ROSCA_ID, $product->state->product?->id, 'número chega como texto do JSON');

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
        $app = new ShoppingApplicationMock(new ShoppingContext($this->scheduler));
        Routes::login($app);
        LoginViewMock::cast($app->rootView()->state->contentView)->presenter->onEnter('fulano', 'fulano');

        self::assertSame(Place::HOME, $app->lastPlace());
        self::assertNull($app->clientPersistentStore()->secure()->get(StorageKeys::AUTH_TOKEN), 'sem "lembrar-me"');
        $app->release();
    }
}
