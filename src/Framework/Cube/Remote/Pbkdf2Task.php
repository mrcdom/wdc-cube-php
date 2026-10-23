<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

/**
 * The task sent to the worker process. It crosses the process boundary serialized
 * (that is why it only carries strings) and the class must be loadable by Composer's autoloader.
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
