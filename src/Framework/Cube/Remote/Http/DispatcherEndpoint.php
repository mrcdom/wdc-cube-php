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
 * `/dispatcher/{id}`: each connection gets its own Fiber that reads messages one at a time —
 * the messages of a connection are never processed in parallel.
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
            while ($message = $client->receive()) {   // suspends the Fiber until the next message arrives
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
