<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation;

use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Commons\Concurrent\Scheduler;
use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePlace;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\SecurityContext;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Shopping\Domain\Product\ProductRepository;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Shopping\Domain\User\UserRepository;
use Wdc\Shopping\Presentation\Presenter\Open\Login\Subject;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartManager;
use Wdc\Shopping\Presentation\Presenter\RootPresenter;
use Wdc\Shopping\Presentation\Security\SecuredProductRepository;
use Wdc\Shopping\Presentation\Security\SecuredPurchaseItemRepository;
use Wdc\Shopping\Presentation\Security\SecuredPurchaseRepository;
use Wdc\Shopping\Presentation\Security\SecuredUserRepository;

/**
 * Uma sessão do Shopping: quem está logado, o carrinho e o acesso aos serviços.
 *
 * Abstrata: o host remoto e o mock de teste completam o que depende do ambiente
 * (cifra da sessão, armazenamento do cliente, histórico/URL).
 */
abstract class ShoppingApplication extends CubeApplication
{
    protected ?Subject $subject = null;

    protected ?CartManager $cart = null;

    private ?SecurityContext $securityContext = null;

    private ?UserRepository $userRepository = null;

    private ?ProductRepository $productRepository = null;

    private ?PurchaseRepository $purchaseRepository = null;

    private ?PurchaseItemRepository $purchaseItemRepository = null;

    public function __construct(public readonly ShoppingContext $context, ?LoggerInterface $logger = null)
    {
        parent::__construct($logger ?? $context->logger);
    }

    /** Cifra um texto com a chave da sessão (AES-GCM) e devolve Base64. */
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

    public function securityContext(): ?SecurityContext
    {
        return $this->securityContext;
    }

    /**
     * Não precisa recriar os repositórios embrulhados: o embrulho lê o contexto da sessão a
     * cada chamada.
     */
    public function setSecurityContext(?SecurityContext $securityContext): void
    {
        $this->securityContext = $securityContext;
    }

    public function tx(): TransactionService
    {
        return $this->context->transactions;
    }

    public function authService(): ?AuthenticationService
    {
        return $this->context->auth;
    }

    public function scheduler(): Scheduler
    {
        return $this->context->scheduler;
    }

    // :: Repositórios: cada chamada roda com o SecurityContext desta sessão

    public function userRepository(): UserRepository
    {
        return $this->userRepository ??= new SecuredUserRepository($this->context->users, $this->securityContext(...));
    }

    public function productRepository(): ProductRepository
    {
        return $this->productRepository ??= new SecuredProductRepository($this->context->products, $this->securityContext(...));
    }

    public function purchaseRepository(): PurchaseRepository
    {
        return $this->purchaseRepository ??= new SecuredPurchaseRepository($this->context->purchases, $this->securityContext(...));
    }

    public function purchaseItemRepository(): PurchaseItemRepository
    {
        return $this->purchaseItemRepository ??= new SecuredPurchaseItemRepository($this->context->purchaseItems, $this->securityContext(...));
    }

    // :: Navegação por nome ("cart", "product?pr-id=3"...)

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
