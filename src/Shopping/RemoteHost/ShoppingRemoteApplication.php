<?php

declare(strict_types=1);

namespace Wdc\Shopping\RemoteHost;

use LogicException;
use Wdc\Framework\Commons\Storage\ClientStorage;
use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\RemoteApplication;
use Wdc\Framework\Cube\Remote\RemoteApplicationRegistry;
use Wdc\Framework\Cube\Remote\RemoteApplicationSupport;
use Wdc\Framework\Cube\Remote\RemoteView;
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

/**
 * A Shopping session served to the React shell.
 */
final class ShoppingRemoteApplication extends ShoppingApplication implements RemoteApplication
{
    private readonly RemoteApplicationSupport $support;

    public function __construct(
        ShoppingContext $context,
        string $id,
        RemoteAppSecurity $security,
        private readonly RemoteApplicationRegistry $registry,
    ) {
        parent::__construct($context);
        self::installViewFactories();
        $this->support = new RemoteApplicationSupport($id, $this, $security, $registry, $context->logger);
        $this->support->postConstruct();
    }

    /**
     * Every presenter gets the same generic view.
     */
    public static function installViewFactories(): void
    {
        RootPresenter::$createView = static fn (RootPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
        LoginPresenter::$createView = static fn (LoginPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
        HomePresenter::$createView = static fn (HomePresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
        ProductPresenter::$createView = static fn (ProductPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
        CartPresenter::$createView = static fn (CartPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
        ReceiptPresenter::$createView = static fn (ReceiptPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
        ProductsPanelPresenter::$createView = static fn (ProductsPanelPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
        PurchasesPanelPresenter::$createView = static fn (PurchasesPanelPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());
    }

    // :: RemoteApplication

    public function remote(): RemoteApplicationSupport
    {
        return $this->support;
    }

    public function cubeApp(): CubeApplication
    {
        return $this;
    }

    public function isAuthenticated(): bool
    {
        return $this->subject() !== null;
    }

    public function rootPresenterView(): ?CubeView
    {
        return $this->rootPresenter()?->view();
    }

    public function performGo(CubeIntent $intent): void
    {
        $this->go($intent);
    }

    public function release(): void
    {
        try {
            $this->support->release();
            parent::release();
        } finally {
            $this->registry->remove($this->support->id);
            $this->logger->info("Application removed: {$this->support->id}");
        }
    }

    // :: ShoppingApplication: what depends on the environment

    public function b64Cipher(string $text): string
    {
        return $this->support->dataSecurity->b64Cipher($text);
    }

    public function b64Decipher(string $b64Text): string
    {
        return $this->support->dataSecurity->b64Decipher($b64Text);
    }

    public function clientIp(): string
    {
        return $this->support->clientIp() ?? '127.0.0.1';
    }

    public function clientSessionStore(): ClientStorage
    {
        return $this->support->clientStorage->session();
    }

    public function clientPersistentStore(): ClientStorage
    {
        return $this->support->clientStorage->persistent();
    }

    public function updateHistory(): void
    {
        $this->support->updateHistory();
    }

    private static function of(ShoppingApplication $app): RemoteApplication
    {
        return $app instanceof RemoteApplication ? $app : throw new LogicException('Remote view outside a remote session');
    }
}
