<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/**
 * How messages reach the client — on the server, the WebSocket connection.
 * Keeping this separate makes the whole protocol testable without a network.
 */
interface RemoteChannel
{
    public function send(string $text): void;

    /** Client IP (honoring X-Forwarded-For), if known. */
    public function clientIp(): ?string;
}
