<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\Server\Request;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use Wdc\Framework\Cube\Remote\RemoteConnection;

/** An Amp WebSocket connection seen as a RemoteConnection. */
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
            // the client is already gone: the session lives on and the next send goes out on reconnect
        }
    }

    public function clientIp(): string
    {
        $forwarded = $this->request->getHeader('x-forwarded-for');
        if ($forwarded !== null && trim($forwarded) !== '') {
            return trim(explode(',', $forwarded)[0]);   // "client, proxy1, proxy2" — keep the first
        }
        $address = $this->client->getRemoteAddress()->toString();

        return preg_replace('/:\d+$/', '', $address) ?? $address;   // strip the port
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
