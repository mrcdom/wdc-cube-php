<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\Server\Request;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use Wdc\Framework\Cube\Remote\RemoteConnection;

/** Uma conexão WebSocket do Amp vista como RemoteConnection. */
final readonly class AmpRemoteConnection implements RemoteConnection
{
    public function __construct(
        private WebsocketClient $client,
        private Request $request,
    ) {
    }

    public function send(string $text): void
    {
        try {
            $this->client->sendText($text);
        } catch (WebsocketClosedException) {
            // o cliente já foi embora: a sessão continua e o próximo envio vai na reconexão
        }
    }

    public function clientIp(): string
    {
        $forwarded = $this->request->getHeader('x-forwarded-for');
        if ($forwarded !== null && trim($forwarded) !== '') {
            return trim(explode(',', $forwarded)[0]);   // "cliente, proxy1, proxy2" — fica o primeiro
        }
        $address = $this->client->getRemoteAddress()->toString();

        return preg_replace('/:\d+$/', '', $address) ?? $address;   // tira a porta
    }

    public function connectionId(): string
    {
        return (string) $this->client->getId();
    }

    public function close(int $code, string $reason): void
    {
        $this->client->close($code, $reason);
    }
}
