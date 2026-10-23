<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

use Closure;

final class CallbackRegistration implements Registration
{
    /** @param Closure(): mixed $onRemove */
    public function __construct(private ?Closure $onRemove)
    {
    }

    public function remove(): void
    {
        $callback = $this->onRemove;
        $this->onRemove = null;   // removing twice does nothing
        if ($callback !== null) {
            $callback();
        }
    }
}
