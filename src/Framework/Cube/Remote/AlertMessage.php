<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/** The alert in the format the React shell reads (`state.alertMessage.{id,args}`). */
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
