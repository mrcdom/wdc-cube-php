<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/** Uma conexão de cliente (no servidor, um WebSocket). */
interface RemoteConnection extends RemoteChannel
{
    /** Identifica esta conexão (uma sessão pode ter várias ao longo da vida: F5, queda de rede). */
    public function connectionId(): string;

    public function close(int $code, string $reason): void;
}
