<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

/** Something that can be cancelled (a scheduled timer, a registered listener). */
interface Registration
{
    public function remove(): void;
}
