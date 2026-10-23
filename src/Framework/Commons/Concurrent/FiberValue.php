<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Revolt\EventLoop\FiberLocal;

/**
 * One value per Fiber: each Fiber sees only what it wrote itself (starts as null). It is the
 * event-loop equivalent of a thread-local variable. The event loop clears the value at the end of
 * each callback.
 *
 * @template T of object
 */
final class FiberValue
{
    /** @var FiberLocal<T|null> */
    private readonly FiberLocal $local;

    /**
     * @param class-string<T> $type    the value's type (for type checking only)
     * @param T|null          $initial what every Fiber sees before writing
     */
    public function __construct(public readonly string $type, private readonly ?object $initial = null)
    {
        $this->local = new FiberLocal(fn (): ?object => $this->initial);
    }

    /** @return T|null */
    public function get(): ?object
    {
        return $this->local->get();
    }

    /** @param T|null $value */
    public function set(?object $value): void
    {
        $this->local->set($value);
    }
}
