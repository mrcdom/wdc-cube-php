<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The server side of the `/dispatcher/{appId}` channel, independent of any WebSocket
 * library: the HTTP server only forwards open, messages and close.
 *
 * - **open**: checks the appId (signed by the server); stores the `app_signature` cookie
 *   (the handshake `secret`); if the session is still alive (F5, network drop), reconnects and resends everything;
 * - **message**: without a cookie, the first message must carry the `secret`; creates the session if needed
 *   and dispatches;
 * - **close**: the session stays alive until it expires — except anonymous ones (or TTL 0), released right away.
 */
final class RemoteDispatcher
{
    public const int CLOSE_SESSION_INVALID = 4001;
    public const int CLOSE_CAPACITY_EXCEEDED = 4003;

    /** @var array<string, array{signature: ?string, pendingSignature: bool, accessToken: ?string, connection: RemoteConnection}> */
    private array $handlers = [];

    public function __construct(
        private readonly RemoteApplicationRegistry $registry,
        private readonly RemoteAppSecurity $security,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onOpen(string $appId, ?string $signatureCookie, RemoteConnection $connection): void
    {
        if (!$this->security->isValidAppId($appId)) {
            $this->logger->warning('WebSocket connection rejected: invalid session ID');
            $connection->close(self::CLOSE_SESSION_INVALID, 'reload_required');

            return;
        }
        $hasCookie = $signatureCookie !== null && $signatureCookie !== '';
        $this->handlers[$appId] = [
            'signature' => $hasCookie ? $signatureCookie : null,
            'pendingSignature' => !$hasCookie,   // clients without a cookie send the secret in the first message
            'accessToken' => null,
            'connection' => $connection,
        ];

        $app = $this->registry->get($appId);
        if ($app !== null) {   // the session survived the connection drop
            $app->remote()->setChannel($connection);
            $app->remote()->resetForReconnect();
        }
    }

    public function onMessage(string $appId, RemoteConnection $connection, string $message): void
    {
        $handler = $this->handlers[$appId] ?? null;
        if ($handler === null) {
            return;
        }
        try {
            $request = $this->parse($appId, $message);
            if ($request === []) {
                return;
            }
            if ($handler['pendingSignature']) {
                $secret = $request['secret'] ?? null;
                if (!is_string($secret) || $secret === '') {
                    $this->logger->warning("WebSocket rejected: first message missing 'secret' for session: {$appId}");
                    $connection->close(self::CLOSE_SESSION_INVALID, 'reload_required');

                    return;
                }
                $handler['signature'] = $secret;
                $handler['pendingSignature'] = false;
                $token = $request['accessToken'] ?? null;
                $handler['accessToken'] = is_string($token) && $token !== '' ? $token : null;
                $this->handlers[$appId] = $handler;
            }

            $app = $this->registry->get($appId);
            if ($app === null) {
                // on creation, the connection secret (cookie) takes precedence over the message's
                $request['secret'] = $handler['signature'];
                if ($handler['accessToken'] !== null) {
                    $request['accessToken'] = $handler['accessToken'];
                    $this->handlers[$appId]['accessToken'] = null;
                }
                $app = $this->registry->getOrCreate($appId, $request);
            }
            $app->remote()->setChannel($connection);
            $app->remote()->extendLife();

            try {
                $app->remote()->sendResponse($request);
            } catch (Throwable $e) {
                $this->logger->error('Error processing WebSocket message', ['exception' => $e]);
                $app->remote()->alertUnexpectedError($e->getMessage(), $e);
            }
        } catch (CapacityExceededException) {
            $this->logger->warning("WebSocket rejected (capacity exceeded) for session: {$appId}");
            $connection->close(self::CLOSE_CAPACITY_EXCEEDED, 'capacity_exceeded');
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error in WebSocket message handler', ['exception' => $e]);
        }
    }

    public function onClose(string $appId, RemoteConnection $connection): void
    {
        $handler = $this->handlers[$appId] ?? null;
        if ($handler === null || $handler['connection']->connectionId() !== $connection->connectionId()) {
            return;   // connection already replaced by another (F5): closing the old one does not count
        }
        unset($this->handlers[$appId]);

        $app = $this->registry->get($appId);
        if ($app !== null) {
            $app->remote()->setChannel(null);
            if (!$app->isAuthenticated() || $this->registry->isImmediateRelease()) {
                $app->release();
            }
        }
    }

    public function onError(string $appId, Throwable $error): void
    {
        $this->logger->warning("WebSocket error for session {$appId}: {$error->getMessage()}", ['exception' => $error]);
        $this->registry->get($appId)?->remote()->alertUnexpectedError($error->getMessage(), $error);
    }

    /** @return array<string, mixed> */
    private function parse(string $appId, string $message): array
    {
        try {
            $request = json_decode($message, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('Failed to parse WebSocket message as JSON', ['exception' => $e]);
            $this->registry->get($appId)?->remote()->alertUnexpectedError($e->getMessage(), $e);

            return [];
        }
        if (!is_array($request) || array_is_list($request)) {
            return [];
        }

        /** @var array<string, mixed> $request */
        return $request;
    }
}
