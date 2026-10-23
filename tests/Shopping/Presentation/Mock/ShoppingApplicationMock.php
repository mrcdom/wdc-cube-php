<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use Wdc\Framework\Commons\Storage\ClientStorage;
use Wdc\Framework\Commons\Storage\InMemoryClientStorage;
use Wdc\Shopping\Presentation\Presenter\Open\Login\LoginPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomePresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Products\ProductsPanelPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\Purchases\PurchasesPanelPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Products\ProductPresenter;
use Wdc\Shopping\Presentation\Presenter\Restricted\Receipt\ReceiptPresenter;
use Wdc\Shopping\Presentation\Presenter\RootPresenter;
use Wdc\Shopping\Presentation\ShoppingApplication;
use Wdc\Shopping\Presentation\ShoppingContext;

/** Test session: fake views, in-memory storage, no history. */
final class ShoppingApplicationMock extends ShoppingApplication
{
    public int $historyUpdates = 0;

    private readonly InMemoryClientStorage $sessionStore;

    private readonly InMemoryClientStorage $persistentStore;

    public function __construct(ShoppingContext $context, ?InMemoryClientStorage $persistentStore = null)
    {
        parent::__construct($context);
        self::installViewMocks();
        $this->sessionStore = new InMemoryClientStorage();
        $this->persistentStore = $persistentStore ?? new InMemoryClientStorage();
    }

    /** Binds each presenter to its test view. */
    public static function installViewMocks(): void
    {
        RootPresenter::$createView = static fn (RootPresenter $p) => new RootViewMock($p);
        LoginPresenter::$createView = static fn (LoginPresenter $p) => new LoginViewMock($p);
        HomePresenter::$createView = static fn (HomePresenter $p) => new HomeViewMock($p);
        CartPresenter::$createView = static fn (CartPresenter $p) => new CartViewMock($p);
        ProductPresenter::$createView = static fn (ProductPresenter $p) => new ProductViewMock($p);
        ReceiptPresenter::$createView = static fn (ReceiptPresenter $p) => new ReceiptViewMock($p);
        ProductsPanelPresenter::$createView = static fn (ProductsPanelPresenter $p) => new ProductsPanelViewMock($p);
        PurchasesPanelPresenter::$createView = static fn (PurchasesPanelPresenter $p) => new PurchasesPanelViewMock($p);
    }

    public function rootView(): RootViewMock
    {
        return RootViewMock::cast($this->rootPresenter()?->view());
    }

    public function updateHistory(): void
    {
        $this->historyUpdates++;
    }

    /** No encrypted channel in the tests: "encrypting" is just Base64. */
    public function b64Cipher(string $text): string
    {
        return base64_encode($text);
    }

    public function b64Decipher(string $b64Text): string
    {
        return (string) base64_decode($b64Text, true);
    }

    public function clientIp(): string
    {
        return '127.0.0.1';
    }

    public function clientSessionStore(): ClientStorage
    {
        return $this->sessionStore;
    }

    public function clientPersistentStore(): ClientStorage
    {
        return $this->persistentStore;
    }
}
