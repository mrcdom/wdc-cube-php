<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Cube\Remote\RemoteDispatcher;

/**
 * `/dispatcher/{id}`: cada conexão ganha uma Fiber própria que lê as mensagens uma a uma —
 * as mensagens de uma conexão nunca são processadas em paralelo.
 */
final readonly class DispatcherEndpoint implements WebsocketClientHandler
{
    public const string SIGNATURE_COOKIE = 'app_signature';

    public function __construct(
        private RemoteDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $args = $request->getAttribute(Router::class);
        $appId = is_array($args) && is_string($args['id'] ?? null) ? $args['id'] : '';
        $connection = new AmpRemoteConnection($client, $request);

        $this->dispatcher->onOpen($appId, $request->getCookie(self::SIGNATURE_COOKIE)?->getValue(), $connection);
        try {
            while ($message = $client->receive()) {   // suspende a Fiber até chegar a próxima mensagem
                $this->dispatcher->onMessage($appId, $connection, $message->buffer());
            }
        } catch (Throwable $e) {
            $this->dispatcher->onError($appId, $e);
        } finally {
            $this->dispatcher->onClose($appId, $connection);
            $this->logger->debug("WebSocket closed for session {$appId}");
        }
    }
}
