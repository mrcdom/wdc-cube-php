<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/** A client connection (on the server, a WebSocket). */
interface RemoteConnection extends RemoteChannel
{
    /** Identifies this connection (a session may have several over its lifetime: F5, network drop). */
    public function connectionId(): string;

    public function close(int $code, string $reason): void;
}
