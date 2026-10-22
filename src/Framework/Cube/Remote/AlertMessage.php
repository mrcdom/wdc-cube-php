<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/** O alerta no formato que o shell React lê (`state.alertMessage.{id,args}`). */
final class AlertMessage
{
    /**
     * @param list<string> $args
     */
    public function __construct(
        public int $id,
        public array $args = [],
    ) {
    }
}
