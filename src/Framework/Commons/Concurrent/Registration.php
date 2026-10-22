<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Concurrent;

/** Algo que pode ser cancelado (um timer agendado, um listener registrado). */
interface Registration
{
    public function remove(): void;
}
