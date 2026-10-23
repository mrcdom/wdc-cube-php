<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\WebsocketAcceptor;

/**
 * Accepts the upgrade and negotiates the `wdc` subprotocol. The shell opens the WebSocket asking for `wdc`; if
 * the server does not answer `Sec-WebSocket-Protocol: wdc`, the browser drops the connection. Amp's
 * default acceptor does not negotiate subprotocols, so this one wraps it.
 */
final readonly class WdcWebsocketAcceptor implements WebsocketAcceptor
{
    public const string SUBPROTOCOL = 'wdc';

    public function __construct(private WebsocketAcceptor $inner = new Rfc6455Acceptor())
    {
    }

    public function handleHandshake(Request $request): Response
    {
        $response = $this->inner->handleHandshake($request);
        if ($response->getStatus() !== HttpStatus::SWITCHING_PROTOCOLS) {
            return $response;
        }
        foreach ($request->getHeaderArray('sec-websocket-protocol') as $header) {
            foreach (array_map('trim', explode(',', $header)) as $protocol) {
                if ($protocol === self::SUBPROTOCOL) {
                    $response->setHeader('sec-websocket-protocol', self::SUBPROTOCOL);

                    return $response;
                }
            }
        }

        return $response;
    }
}
