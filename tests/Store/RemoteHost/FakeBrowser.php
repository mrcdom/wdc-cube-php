<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\RemoteHost;

use PHPUnit\Framework\Assert;
use Wdc\Framework\Cube\Remote\RemoteBrowserPresenter;
use Wdc\Framework\Cube\Remote\RemoteDispatcher;
use Wdc\Store\RemoteHost\StoreRemoteHost;

/**
 * Plays the role of the React shell on the client side:
 * - handshake just like DataSecurity.ts (random password, PBKDF2, RSA from `app_skey`);
 * - messages in the FlushRequestContext.ts format (`event`, formData per view, `requestId`);
 * - applies the responses: `states` (replaced by id), `uri`, `storage` (decrypted), `releasedViews`.
 */
final class FakeBrowser
{
    public const string BROWSER = RemoteBrowserPresenter::CLASS_ID . ':0';

    public readonly string $appId;

    public string $secret;

    public RecordingConnection $connection;

    /** @var array<string, array<string, mixed>> known views, by id */
    public array $views = [];

    public ?string $uri = null;

    /** @var list<string> */
    public array $released = [];

    /** @var list<array<string, mixed>> messages received since the last `take()` */
    public array $inbox = [];

    private int $requestId = 0;

    private ClientCrypto $crypto;

    /**
     * @param array<string, array<string, string>> $storage what the browser already stored (in plain text)
     */
    public function __construct(
        private readonly StoreRemoteHost $host,
        private readonly RemoteDispatcher $dispatcher,
        public array $storage = ['session' => [], 'persistent' => [], 'persistent-secure' => []],
    ) {
        $this->appId = $host->security->newAppId();   // the `app_id` cookie from index.html
        $this->connection = new RecordingConnection();
        $this->handshake();
    }

    /** The DataSecurity.ts handshake (see ClientCrypto), with the key that would come in the `app_skey` cookie. */
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

    /** Browser event -1: the shell boot, with the handshake and the browser storage. */
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

    /** Applies what the server sent (the FlushRequestContext.onmessage processing). */
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
                $this->views[$state['#']] = $state;   // replaces the view's whole state
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
        Assert::assertNotNull($vsid, 'expected view is not in the state');
        Assert::assertArrayHasKey($vsid, $this->views, "the client does not know view {$vsid}");

        return $this->views[$vsid];
    }

    /**
     * The screen inside the root: login or home.
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
