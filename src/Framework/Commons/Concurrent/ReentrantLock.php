<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use Closure;
use Fiber;

/**
 * Reentrant mutual exclusion between Fibers: the Fiber that already holds the lock can take it
 * again (a guarded call that calls another one). The others wait — suspended, without blocking
 * the event loop.
 *
 * It only matters when the guarded work suspends the Fiber (a database query, for example):
 * without it, another Fiber could touch the same state halfway through.
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
