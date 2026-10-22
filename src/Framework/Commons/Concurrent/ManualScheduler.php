<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Closure;

/**
 * Agendador de relógio manual, para testes: nada roda sozinho; `advance()` move o relógio e
 * executa o que venceu, em ordem de vencimento.
 */
final class ManualScheduler implements Scheduler
{
    private float $now = 0.0;

    private int $nextId = 0;

    /** @var array<int, array{at: float, task: Closure(): mixed, period: ?float}> */
    private array $tasks = [];

    public function schedule(Closure $task, float $delaySeconds): Registration
    {
        return $this->add($task, $delaySeconds, null);
    }

    public function scheduleAtFixedRate(Closure $task, float $initialDelaySeconds, float $periodSeconds): Registration
    {
        return $this->add($task, $initialDelaySeconds, $periodSeconds);
    }

    /** Avança o relógio e executa as tarefas vencidas. */
    public function advance(float $seconds): void
    {
        $target = $this->now + $seconds;
        while (true) {
            $due = array_filter($this->tasks, static fn (array $t): bool => $t['at'] <= $target);
            if ($due === []) {
                break;
            }
            uasort($due, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $id = array_key_first($due);
            $task = $this->tasks[$id];
            $this->now = $task['at'];
            if ($task['period'] === null) {
                unset($this->tasks[$id]);
            } else {
                $this->tasks[$id]['at'] += $task['period'];
            }
            ($task['task'])();
        }
        $this->now = $target;
    }

    public function pending(): int
    {
        return count($this->tasks);
    }

    /** @param Closure(): mixed $task */
    private function add(Closure $task, float $delay, ?float $period): Registration
    {
        $id = $this->nextId++;
        $this->tasks[$id] = ['at' => $this->now + $delay, 'task' => $task, 'period' => $period];

        return new CallbackRegistration(function () use ($id): void {
            unset($this->tasks[$id]);
        });
    }
}
