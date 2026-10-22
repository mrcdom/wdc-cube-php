<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\RemoteHost;

use PHPUnit\Framework\Assert;
use Wdc\Framework\Cube\Remote\RemoteBrowserPresenter;
use Wdc\Framework\Cube\Remote\RemoteDispatcher;
use Wdc\Shopping\RemoteHost\ShoppingRemoteHost;

/**
 * Faz o papel do shell React do lado do cliente:
 * - handshake igual ao DataSecurity.ts (senha aleatória, PBKDF2, RSA do `app_skey`);
 * - mensagens no formato do FlushRequestContext.ts (`event`, formData por view, `requestId`);
 * - aplica as respostas: `states` (substitui por id), `uri`, `storage` (decifra), `releasedViews`.
 */
final class FakeBrowser
{
    public const string BROWSER = RemoteBrowserPresenter::CLASS_ID . ':0';

    public readonly string $appId;

    public string $secret;

    public RecordingConnection $connection;

    /** @var array<string, array<string, mixed>> views conhecidas, por id */
    public array $views = [];

    public ?string $uri = null;

    /** @var list<string> */
    public array $released = [];

    /** @var list<array<string, mixed>> mensagens recebidas desde o último `take()` */
    public array $inbox = [];

    private int $requestId = 0;

    private ClientCrypto $crypto;

    /**
     * @param array<string, array<string, string>> $storage o que o browser já guardava (em claro)
     */
    public function __construct(
        private readonly ShoppingRemoteHost $host,
        private readonly RemoteDispatcher $dispatcher,
        public array $storage = ['session' => [], 'persistent' => [], 'persistent-secure' => []],
    ) {
        $this->appId = $host->security->newAppId();   // o cookie `app_id` do index.html
        $this->connection = new RecordingConnection();
        $this->handshake();
    }

    /** O handshake do DataSecurity.ts (ver ClientCrypto), com a chave que viria no cookie `app_skey`. */
    public function handshake(): void
    {
        $this->crypto = ClientCrypto::handshake($this->host->security->webKey());
        $this->secret = $this->crypto->secret;
    }

    public function open(bool $withCookie = true): void
    {
        $this->dispatcher->onOpen($this->appId, $withCookie ? $this->secret : null, $this->connection);
    }

    public function reconnect(): RecordingConnection
    {
        $old = $this->connection;
        $this->connection = new RecordingConnection();
        $this->open();

        return $old;
    }

    public function close(): void
    {
        $this->dispatcher->onClose($this->appId, $this->connection);
    }

    /** Evento -1 do Browser: o boot do shell, com handshake e o storage do browser. */
    public function start(string $path = '/'): void
    {
        $bootstrap = [];
        foreach ($this->storage as $scope => $values) {
            foreach ($values as $k => $v) {
                $bootstrap[$scope][$k] = $this->cipher($v);
            }
        }
        $this->send([
            'event' => [self::BROWSER . ':-1'],
            self::BROWSER => ['p.path' => $path],
            'secret' => $this->secret,
            ...($bootstrap === [] ? [] : ['storage' => $bootstrap]),
        ]);
    }

    /** @param array<string, mixed> $form */
    public function submit(string $viewId, int $eventCode, array $form = []): void
    {
        $this->send(['event' => ["{$viewId}:{$eventCode}"], $viewId => $form]);
    }

    /** @param array<string, mixed> $message */
    public function send(array $message, bool $withRequestId = true): void
    {
        if ($withRequestId) {
            $message['requestId'] = $this->requestId++;
        }
        $this->sendRaw(json_encode($message, JSON_THROW_ON_ERROR));
    }

    public function sendRaw(string $json): void
    {
        $this->dispatcher->onMessage($this->appId, $this->connection, $json);
        $this->receive();
    }

    /** Aplica o que o servidor mandou (o processamento do FlushRequestContext.onmessage). */
    public function receive(): void
    {
        while ($this->connection->sent !== []) {
            $message = json_decode(array_shift($this->connection->sent), true, 64, JSON_THROW_ON_ERROR);
            Assert::assertIsArray($message);
            $this->inbox[] = $message;
            foreach ($message['releasedViews'] ?? [] as $vsid) {
                $this->released[] = $vsid;
                unset($this->views[$vsid]);
            }
            if (isset($message['uri'])) {
                $this->uri = $message['uri'];
            }
            foreach ($message['states'] ?? [] as $state) {
                $this->views[$state['#']] = $state;   // substitui o estado inteiro da view
            }
            foreach ($message['storage'] ?? [] as $scope => $values) {
                foreach ($values as $k => $v) {
                    if ($v === null || $v === '') {
                        unset($this->storage[$scope][$k]);
                    } else {
                        $this->storage[$scope][$k] = $this->decipher($v);
                    }
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function take(): array
    {
        $inbox = $this->inbox;
        $this->inbox = [];

        return $inbox;
    }

    /** @return array<string, mixed> */
    public function state(?string $vsid): array
    {
        Assert::assertNotNull($vsid, 'view esperada não está no estado');
        Assert::assertArrayHasKey($vsid, $this->views, "o cliente não conhece a view {$vsid}");

        return $this->views[$vsid];
    }

    /**
     * A tela dentro da raiz: login ou home.
     *
     * @return array<string, mixed>
     */
    public function screen(): array
    {
        $root = $this->state($this->state(self::BROWSER)['contentViewId'] ?? null);

        return $this->state($root['contentViewId'] ?? null);
    }

    public static function classOf(string $vsid): string
    {
        return explode(':', $vsid)[0];
    }

    public function cipher(string $text): string
    {
        return $this->crypto->cipher($text);
    }

    public function decipher(string $b64): string
    {
        return $this->crypto->decipher($b64);
    }
}
