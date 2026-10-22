<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Amp\Parallel\Worker\WorkerPool;

use function Amp\Parallel\Worker\workerPool;

/**
 * PBKDF2 num processo do pool do amphp/parallel. Quem chama espera (a sua Fiber é suspensa)
 * e o event loop segue atendendo as outras sessões nesse meio tempo.
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
