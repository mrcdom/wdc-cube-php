<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wdc\Framework\Commons\Concurrent\Scheduler;

/**
 * What the presentation shares across sessions that is not a service found through `Beans`:
 * the scheduler, the logger and the simulation options. Repositories, transactions and
 * authentication live in `Beans`.
 */
final readonly class StoreContext
{
    /** @var Closure(float): void */
    public Closure $sleep;

    /** @param (Closure(float): void)|null $sleep waits without blocking the event loop (simulated slow login) */
    public function __construct(
        public Scheduler $scheduler,
        public LoggerInterface $logger = new NullLogger(),
        public bool $simulateSlowLogin = false,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            \Amp\delay($seconds);   // suspends only this session's Fiber; the others keep going
        };
    }
}
