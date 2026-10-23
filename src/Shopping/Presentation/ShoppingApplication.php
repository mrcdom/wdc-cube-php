<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation;

use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Commons\Concurrent\Scheduler;
use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Presenter\Open\Login\Subject;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartManager;
use Wdc\Shopping\Presentation\Presenter\RootPresenter;

/**
 * A Shopping session: who is logged in, the cart and access to the services.
 *
 * Abstract: the remote host and the test mock fill in what depends on the environment
 * (session cipher, client storage, history/URL).
 */
abstract class ShoppingApplication extends CubeApplication
{
    protected ?Subject $subject = null;

    protected ?CartManager $cart = null;

    public function __construct(public readonly ShoppingContext $context, ?LoggerInterface $logger = null)
    {
        parent::__construct($logger ?? $context->logger);
    }

    /** Encrypts a text with the session key (AES-GCM) and returns Base64. */
    abstract public function b64Cipher(string $text): string;

    abstract public function b64Decipher(string $b64Text): string;

    abstract public function clientIp(): string;

    public function rootPlace(): CubePlace
    {
        return Place::ROOT;
    }

    public function rootPresenter(): ?RootPresenter
    {
        $presenter = $this->presenterOf(Place::ROOT);

        return $presenter instanceof RootPresenter ? $presenter : null;
    }

    public function subject(): ?Subject
    {
        return $this->subject;
    }

    public function setSubject(?Subject $subject): void
    {
        $this->subject = $subject;
    }

    public function cart(): ?CartManager
    {
        return $this->cart;
    }

    public function setCart(?CartManager $cart): ?CartManager
    {
        $old = $this->cart;
        $this->cart = $cart;

        return $old;
    }

    public function scheduler(): Scheduler
    {
        return $this->context->scheduler;
    }

    // :: Navigation by name ("cart", "product?pr-id=3"...)

    public function go(string|CubeIntent $target): bool
    {
        $intent = is_string($target) ? CubeIntent::parse($target) : $target;
        $place = Place::fromName($intent->place()?->name() ?? '') ?? Place::ROOT;

        return $place->go($this, $intent);
    }

    public function alertUnexpectedError(string $message, Throwable $caught): void
    {
        $root = $this->rootPresenter();
        if ($root !== null) {
            $root->alertUnexpectedError($message, $caught);
        } else {
            $this->logger->error($message, ['exception' => $caught]);
        }
    }
}
