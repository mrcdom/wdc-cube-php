<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wdc\Framework\Commons\Concurrent\Scheduler;

/**
 * O que a apresentação compartilha entre as sessões e não é um serviço achado pelo `Beans`:
 * o agendador, o log e as opções de simulação. Os repositórios, as transações e a autenticação
 * ficam no `Beans`.
 */
final readonly class ShoppingContext
{
    /** @var Closure(float): void */
    public Closure $sleep;

    /** @param (Closure(float): void)|null $sleep espera sem travar o event loop (login lento simulado) */
    public function __construct(
        public Scheduler $scheduler,
        public LoggerInterface $logger = new NullLogger(),
        public bool $simulateSlowLogin = false,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            \Amp\delay($seconds);   // suspende só a Fiber desta sessão; as outras seguem
        };
    }
}
