<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wdc\Framework\Commons\Concurrent\Scheduler;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Shopping\Domain\Product\ProductRepository;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Shopping\Domain\User\UserRepository;
use Wdc\Shopping\Persistence\ShoppingRepositories;

/**
 * Os serviços de que a apresentação precisa, montados uma vez no composition root e
 * compartilhados por todas as sessões. Vão juntos num objeto, em vez de holders estáticos.
 */
final readonly class ShoppingContext
{
    /** @var Closure(float): void */
    public Closure $sleep;

    /**
     * @param AuthenticationService|null    $auth  null ⇒ login confere a senha direto no repositório
     * @param (Closure(float): void)|null   $sleep espera sem travar o event loop (login lento simulado)
     */
    public function __construct(
        public UserRepository $users,
        public ProductRepository $products,
        public PurchaseRepository $purchases,
        public PurchaseItemRepository $purchaseItems,
        public TransactionService $transactions,
        public Scheduler $scheduler,
        public ?AuthenticationService $auth = null,
        public LoggerInterface $logger = new NullLogger(),
        public bool $simulateSlowLogin = false,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            \Amp\delay($seconds);   // suspende só a Fiber desta sessão; as outras seguem
        };
    }

    public static function of(
        ShoppingRepositories $repos,
        Scheduler $scheduler,
        ?AuthenticationService $auth = null,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        return new self(
            $repos->users,
            $repos->products,
            $repos->purchases,
            $repos->purchaseItems,
            $repos->transactions,
            $scheduler,
            $auth,
            $logger,
        );
    }
}
