<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Closure;

/**
 * Schedules work for later (one-off or periodic).
 *
 * On the server the implementation uses the event loop (the work runs on the same thread, when
 * the loop gets there); in tests, a manual scheduler that only runs when told to.
 */
interface Scheduler
{
    /** @param Closure(): mixed $task */
    public function schedule(Closure $task, float $delaySeconds): Registration;

    /** @param Closure(): mixed $task */
    public function scheduleAtFixedRate(Closure $task, float $initialDelaySeconds, float $periodSeconds): Registration;
}
