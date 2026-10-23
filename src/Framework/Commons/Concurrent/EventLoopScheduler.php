<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Throwable;

/**
 * The server scheduler, on top of the Revolt event loop (the one Amp uses).
 *
 * Tasks run on the same thread as everything else, when the loop gets to them — never in
 * parallel with a request. An exception in a task is logged and does not bring the server down.
 */
final class EventLoopScheduler implements Scheduler
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function schedule(Closure $task, float $delaySeconds): Registration
    {
        $id = EventLoop::delay(max(0.0, $delaySeconds), fn () => $this->run($task));

        return new CallbackRegistration(static fn () => EventLoop::cancel($id));
    }

    public function scheduleAtFixedRate(Closure $task, float $initialDelaySeconds, float $periodSeconds): Registration
    {
        $repeatId = null;
        $delayId = EventLoop::delay(max(0.0, $initialDelaySeconds), function () use ($task, $periodSeconds, &$repeatId): void {
            $this->run($task);
            $repeatId = EventLoop::repeat($periodSeconds, fn () => $this->run($task));
        });

        return new CallbackRegistration(static function () use ($delayId, &$repeatId): void {
            EventLoop::cancel($delayId);
            if (is_string($repeatId)) {
                EventLoop::cancel($repeatId);
            }
        });
    }

    /** @param Closure(): mixed $task */
    private function run(Closure $task): void
    {
        try {
            $task();
        } catch (Throwable $e) {
            $this->logger->error('Scheduled task failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
