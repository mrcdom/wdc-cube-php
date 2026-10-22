<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Throwable;

/**
 * Agendador do servidor, sobre o event loop do Revolt (que o Amp usa).
 *
 * As tarefas rodam na mesma thread de tudo o mais, quando o loop chega até elas — nunca
 * em paralelo com um request. Uma exceção numa tarefa é registrada e não derruba o servidor.
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
