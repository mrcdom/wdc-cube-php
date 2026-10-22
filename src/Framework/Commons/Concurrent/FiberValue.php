<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Revolt\EventLoop\FiberLocal;

/**
 * Um valor por Fiber: cada Fiber enxerga só o que ela mesma gravou (começa em null). É o
 * equivalente, no event loop, de uma variável por thread. O event loop limpa o valor ao fim de
 * cada callback.
 *
 * @template T of object
 */
final class FiberValue
{
    /** @var FiberLocal<T|null> */
    private readonly FiberLocal $local;

    /**
     * @param class-string<T> $type    o tipo do valor (só para a checagem de tipos)
     * @param T|null          $initial o que toda Fiber enxerga antes de gravar
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
