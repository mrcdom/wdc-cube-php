<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\RemoteApplicationRegistry;
use Wdc\Framework\Cube\Remote\ViewStateSerializer;

/**
 * `GET /api/session/init` — o início de sessão para clientes que não são browser (desktop,
 * mobile, probes): devolve `{appId, appSKey}` em JSON, no lugar dos cookies do index.html.
 * O cliente manda o `secret` na 1ª mensagem do WebSocket.
 */
final readonly class SessionInitHandler implements RequestHandler
{
    public function __construct(
        private RemoteAppSecurity $security,
        private RemoteApplicationRegistry $registry,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        $headers = ['content-type' => 'application/json', 'cache-control' => 'no-cache, no-store', 'pragma' => 'no-cache', 'expires' => '0'];
        if ($this->registry->isFull()) {
            return new Response(HttpStatus::SERVICE_UNAVAILABLE, $headers, '{"error":"capacity_exceeded"}');
        }

        return new Response(HttpStatus::OK, $headers, ViewStateSerializer::json([
            'appId' => $this->security->newAppId(),
            'appSKey' => $this->security->webKey(),
        ]));
    }
}
