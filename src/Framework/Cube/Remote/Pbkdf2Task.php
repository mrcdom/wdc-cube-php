<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

/**
 * A tarefa enviada ao processo-worker. Ela atravessa a fronteira entre processos serializada
 * (por isso só leva strings) e a classe precisa ser carregável pelo autoload do Composer.
 *
 * @implements Task<string, never, never>
 */
final readonly class Pbkdf2Task implements Task
{
    public function __construct(
        private string $password,
        private string $salt,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): string
    {
        return new DirectKeyDerivation()->derive($this->password, $this->salt);
    }
}
