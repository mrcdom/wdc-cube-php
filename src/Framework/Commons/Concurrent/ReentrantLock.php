<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use Closure;
use Fiber;

/**
 * Exclusão mútua entre Fibers, reentrante: a Fiber que já tem o lock pode pegá-lo de novo (uma
 * chamada protegida que chama outra). As outras esperam — suspensas, sem travar o event loop.
 *
 * Só faz diferença quando o trabalho protegido suspende a Fiber (uma consulta ao banco, por
 * exemplo): sem isso, outra Fiber poderia mexer no mesmo estado no meio do caminho.
 */
final class ReentrantLock
{
    private readonly LocalMutex $mutex;

    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    private ?Fiber $owner = null;

    private int $depth = 0;

    private ?Lock $lock = null;

    public function __construct()
    {
        $this->mutex = new LocalMutex();
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        $fiber = Fiber::getCurrent();
        if ($this->depth > 0 && $this->owner === $fiber) {
            $this->depth++;
            try {
                return $work();
            } finally {
                $this->depth--;
            }
        }

        $lock = $this->mutex->acquire();
        $this->owner = $fiber;
        $this->depth = 1;
        $this->lock = $lock;
        try {
            return $work();
        } finally {
            $this->depth = 0;
            $this->owner = null;
            $this->lock = null;
            $lock->release();
        }
    }

    public function isHeldByCurrentFiber(): bool
    {
        return $this->depth > 0 && $this->owner === Fiber::getCurrent() && $this->lock !== null;
    }
}
