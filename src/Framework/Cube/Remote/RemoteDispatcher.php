<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * O lado servidor do canal `/dispatcher/{appId}`, sem depender
 * de biblioteca de WebSocket: o servidor HTTP só repassa abertura, mensagens e fechamento.
 *
 * - **abertura**: confere o appId (assinado pelo servidor); guarda o cookie `app_signature`
 *   (o `secret` do handshake); se a sessão ainda vive (F5, queda de rede), reconecta e reenvia tudo;
 * - **mensagem**: sem cookie, a 1ª mensagem tem de trazer o `secret`; cria a sessão se preciso
 *   e despacha;
 * - **fechamento**: a sessão fica viva até expirar — menos as anônimas (ou com TTL 0), liberadas já.
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
            'pendingSignature' => !$hasCookie,   // clientes sem cookie mandam o secret na 1ª mensagem
            'accessToken' => null,
            'connection' => $connection,
        ];

        $app = $this->registry->get($appId);
        if ($app !== null) {   // a sessão sobreviveu à queda da conexão
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
                // na criação, o secret da conexão (cookie) vale mais que o da mensagem
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
            return;   // conexão já substituída por outra (F5): o fechamento da velha não conta
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
