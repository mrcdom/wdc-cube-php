<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Closure;

/**
 * Agenda trabalho para depois (execução única ou periódica).
 *
 * No servidor a implementação usa o event loop (o trabalho roda na mesma thread, quando o
 * loop chegar lá); nos testes, um agendador manual que só executa quando mandado.
 */
interface Scheduler
{
    /** @param Closure(): mixed $task */
    public function schedule(Closure $task, float $delaySeconds): Registration;

    /** @param Closure(): mixed $task */
    public function scheduleAtFixedRate(Closure $task, float $initialDelaySeconds, float $periodSeconds): Registration;
}
