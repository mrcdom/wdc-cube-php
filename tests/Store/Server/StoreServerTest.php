<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Server;

use Wdc\Framework\Commons\Beans;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Wdc\Framework\Cube\Remote\RemoteDispatcher;
use Wdc\Framework\Domain\Config\AppConfig;
use Wdc\Store\Domain\StoreConfig;
use Wdc\Store\Server\StoreServer;
use Wdc\Store\Persistence\StoreSchema;
use Wdc\Tests\Store\RemoteHost\ClientCrypto;
use Wdc\Tests\Support\TestDatabase;

use function Amp\Websocket\Client\connect;

/**
 * The real server, end to end: HTTP + WebSocket on a free port, with a real WebSocket
 * client following the React shell's steps (index.html cookies, handshake, `wdc`).
 */
final class StoreServerTest extends TestCase
{
    private const string BROWSER = '7b32e816a191:0';

    private string $dir;

    private StoreServer $server;

    private HttpClient $http;

    private string $base;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/work/frontend/store.shell.react/index.html')) {
            self::markTestSkipped('React shell missing: run bin/build-frontend.sh');
        }
        $this->dir = sys_get_temp_dir() . '/wdc-server-' . bin2hex(random_bytes(4));
        mkdir("{$this->dir}/config", 0o775, true);
        symlink(dirname(__DIR__, 3) . '/work/frontend', "{$this->dir}/frontend");
        $config = StoreConfig::configure(AppConfig::fromArray(TestDatabase::serverConfig() + [
            'app.basedir' => '..',
            'server.devMode' => 'true',
            'security.jwt.secret' => 'e2e-secret',
        ], "{$this->dir}/config/application.toml"));

        $this->server = new StoreServer($config, new NullLogger());
        StoreSchema::reset($this->server->db);
        $this->server->start(0, '127.0.0.1');   // port 0: the system picks a free one
        $this->base = '127.0.0.1:' . $this->server->port();
        $this->http = HttpClientBuilder::buildDefault();
    }

    protected function tearDown(): void
    {
        // without the built frontend, setUp skips the test before creating the server
        if (isset($this->server)) {
            $this->server->stop();
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        Beans::clear();
    }

    /** @return array{string, string} app_id and app_skey, as the browser receives them */
    private function loadIndex(): array
    {
        $response = $this->http->request(new Request("http://{$this->base}/store.shell.react/index.html"));
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('<html', $response->getBody()->buffer());
        $cookies = [];
        foreach ($response->getHeaderArray('set-cookie') as $header) {
            $cookie = ResponseCookie::fromHeader($header);
            if ($cookie !== null) {
                $cookies[$cookie->getName()] = $cookie;
            }
        }
        self::assertArrayHasKey('app_id', $cookies);
        self::assertSame(10, $cookies['app_id']->getMaxAge());
        self::assertFalse($cookies['app_id']->isHttpOnly(), 'the shell JavaScript needs to read the cookie');

        return [$cookies['app_id']->getValue(), $cookies['app_skey']->getValue()];
    }

    private function open(string $appId, ?ClientCrypto $crypto): WebsocketConnection
    {
        $handshake = new WebsocketHandshake("ws://{$this->base}/dispatcher/{$appId}", ['sec-websocket-protocol' => 'wdc']);
        if ($crypto !== null) {
            $handshake = $handshake->withHeader('cookie', "app_signature={$crypto->secret}");
        }

        return connect($handshake);
    }

    /**
     * Sends and waits for the response to that requestId (background pushes in between are applied too).
     *
     * @param array<string, mixed>                $message
     * @param array<mixed>                         $views views by id, as the shell stores them
     */
    private static function exchange(WebsocketConnection $ws, array $message, array &$views): void
    {
        $ws->sendText(json_encode($message, JSON_THROW_ON_ERROR));
        do {
            $incoming = $ws->receive();
            self::assertNotNull($incoming, 'the server closed the connection');
            $response = json_decode($incoming->buffer(), true, 64, JSON_THROW_ON_ERROR);
            foreach ($response['states'] ?? [] as $state) {
                $views[$state['#']] = $state;
            }
        } while (($response['requestId'] ?? null) !== $message['requestId']);
    }

    /** The frontend is the first URL segment, and only a folder with an index.html counts - never outside it. */
    #[Test]
    public function frontendContexts_areFoldersWithAnIndex_andNeverEscapeIt(): void
    {
        $client = (new HttpClientBuilder())->followRedirects(0)->build();   // see the 302, do not follow it
        $status = function (string $path) use ($client): array {
            $response = $client->request(new Request("http://{$this->base}{$path}"));

            return [$response->getStatus(), $response->getHeader('location')];
        };

        self::assertSame([302, '/' . StoreServer::DEFAULT_SHELL . '/index.html'], $status('/'));
        self::assertSame(200, $status('/' . StoreServer::DEFAULT_SHELL . '/index.html')[0]);
        self::assertSame([302, '/' . StoreServer::DEFAULT_SHELL . '/index.html'], $status('/' . StoreServer::DEFAULT_SHELL . '/some/screen'), 'an SPA route goes back to the index');
        self::assertSame(404, $status('/doesnotexist/index.html')[0]);
        self::assertSame(404, $status('/..%2F..%2Fcomposer.json/index.html')[0], 'does not leave the frontends folder');
        self::assertSame(404, $status('/.hidden/index.html')[0]);
    }

    #[Test]
    public function purchaseOverRealHttpAndWebsocket(): void
    {
        [$appId, $webKey] = $this->loadIndex();
        $crypto = ClientCrypto::handshake($webKey);
        $ws = $this->open($appId, $crypto);
        self::assertSame('wdc', $ws->getHandshakeResponse()->getHeader('sec-websocket-protocol'), 'negotiated subprotocol');

        $views = [];
        self::exchange($ws, ['event' => [self::BROWSER . ':-1'], self::BROWSER => ['p.path' => '/'], 'requestId' => 0], $views);
        $screen = static function () use (&$views): array {
            $root = $views[$views[self::BROWSER]['contentViewId']];

            return $views[$root['contentViewId']];
        };
        $login = $screen()['#'];
        self::assertStringStartsWith('c677cda52d14:', $login);

        self::exchange($ws, ['event' => ["{$login}:1"], $login => ['p.userName' => 'john', 'p.password' => $crypto->cipher('john')], 'requestId' => 1], $views);
        $home = $screen();
        self::assertSame('John Doe', $home['nickName']);

        self::exchange($ws, ['event' => ["{$home['productsPanelViewId']}:1"], $home['productsPanelViewId'] => ['p.productId' => 1], 'requestId' => 2], $views);
        $product = $views[$screen()['contentViewId']];
        self::assertSame('Wilson ball', $product['product']['name']);

        self::exchange($ws, ['event' => ["{$product['#']}:2"], $product['#'] => ['p.quantity' => 3], 'requestId' => 3], $views);
        $cart = $views[$screen()['contentViewId']];
        self::exchange($ws, ['event' => ["{$cart['#']}:1"], 'requestId' => 4], $views);

        $receipt = $views[$screen()['contentViewId']];
        self::assertTrue($receipt['notifySuccess']);
        self::assertEqualsWithDelta(135.9, $receipt['receipt']['total'], 0.001);

        $image = $this->http->request(new Request("http://{$this->base}/image/product/1.png"));
        self::assertSame('image/png', $image->getHeader('content-type'));

        $ws->close();
    }

    #[Test]
    public function sessionInit_withoutCookies_sendsSecretInFirstMessage(): void
    {
        $init = json_decode($this->http->request(new Request("http://{$this->base}/api/session/init"))->getBody()->buffer(), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($init);
        $crypto = ClientCrypto::handshake($init['appSKey']);
        $ws = $this->open($init['appId'], null);   // no cookie: the secret goes in the 1st message

        $ws->sendText(json_encode(['secret' => $crypto->secret, 'event' => []], JSON_THROW_ON_ERROR));   // no requestId
        $push = $ws->receive();   // the background push (50 ms) brings the new session's views
        self::assertNotNull($push);
        $states = json_decode($push->buffer(), true, 64, JSON_THROW_ON_ERROR)['states'] ?? [];
        self::assertContains(self::BROWSER, array_column($states, '#'));
        $ws->close();
    }

    #[Test]
    public function invalidAppId_isClosedWith4001(): void
    {
        $ws = $this->open('forged.xyz', null);
        self::assertNull($ws->receive());
        self::assertSame(RemoteDispatcher::CLOSE_SESSION_INVALID, $ws->getCloseInfo()->getCode());
        self::assertSame('reload_required', $ws->getCloseInfo()->getReason());
    }

    #[Test]
    public function devReset_restoresTheSeed(): void
    {
        $this->server->db->execute('DELETE FROM EN_PURCHASEITEM');
        $response = $this->http->request(new Request("http://{$this->base}/__dev/db-reset", 'POST'));

        self::assertSame(200, $response->getStatus());
        self::assertSame(3, (int) $this->server->db->fetchValue('SELECT COUNT(*) FROM EN_PURCHASEITEM'));
    }
}
