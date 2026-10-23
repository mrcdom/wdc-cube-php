<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Amp\Parallel\Worker\WorkerPool;

use function Amp\Parallel\Worker\workerPool;

/**
 * PBKDF2 in a process from the amphp/parallel pool. The caller waits (its Fiber is suspended)
 * and the event loop keeps serving the other sessions in the meantime.
 */
final readonly class ParallelKeyDerivation implements KeyDerivation
{
    private WorkerPool $pool;

    public function __construct(?WorkerPool $pool = null)
    {
        $this->pool = $pool ?? workerPool();
    }

    public function derive(string $password, string $salt): string
    {
        return $this->pool->submit(new Pbkdf2Task($password, $salt))->await();
    }
}
