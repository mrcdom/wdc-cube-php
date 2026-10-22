<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

/**
 * Por onde as mensagens chegam ao cliente — no servidor, a conexão WebSocket.
 * Separar isto deixa o protocolo inteiro testável sem rede.
 */
interface RemoteChannel
{
    public function send(string $text): void;

    /** IP do cliente (respeitando X-Forwarded-For), se conhecido. */
    public function clientIp(): ?string;
}
