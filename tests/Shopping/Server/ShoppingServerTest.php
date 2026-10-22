<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Server;

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
use Wdc\Shopping\Domain\ShoppingConfig;
use Wdc\Shopping\Server\ShoppingServer;
use Wdc\Shopping\Persistence\ShoppingSchema;
use Wdc\Tests\Shopping\RemoteHost\ClientCrypto;
use Wdc\Tests\Support\TestDatabase;

use function Amp\Websocket\Client\connect;

/**
 * O servidor de verdade, de ponta a ponta: HTTP + WebSocket numa porta livre, com um cliente
 * WebSocket real seguindo os passos do shell React (cookies do index.html, handshake, `wdc`).
 */
final class ShoppingServerTest extends TestCase
{
    private const string BROWSER = '7b32e816a191:0';

    private string $dir;

    private ShoppingServer $server;

    private HttpClient $http;

    private string $base;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/work/frontend/remote.shell.react/index.html')) {
            self::markTestSkipped('shell React ausente: rode bin/build-frontend.sh');
        }
        $this->dir = sys_get_temp_dir() . '/wdc-server-' . bin2hex(random_bytes(4));
        mkdir("{$this->dir}/config", 0o775, true);
        symlink(dirname(__DIR__, 3) . '/work/frontend', "{$this->dir}/frontend");
        $config = ShoppingConfig::configure(AppConfig::fromArray(TestDatabase::serverConfig() + [
            'app.basedir' => '..',
            'server.devMode' => 'true',
            'security.jwt.secret' => 'segredo-e2e',
        ], "{$this->dir}/config/application.toml"));

        $this->server = new ShoppingServer($config, new NullLogger());
        ShoppingSchema::reset($this->server->repos->db);
        $this->server->start(0, '127.0.0.1');   // porta 0: o sistema escolhe uma livre
        $this->base = '127.0.0.1:' . $this->server->port();
        $this->http = HttpClientBuilder::buildDefault();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array{string, string} app_id e app_skey, como o browser os recebe */
    private function loadIndex(): array
    {
        $response = $this->http->request(new Request("http://{$this->base}/remote.shell.react/index.html"));
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
        self::assertFalse($cookies['app_id']->isHttpOnly(), 'o JavaScript do shell precisa ler o cookie');

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
     * Manda e espera a resposta daquele requestId (pushes de fundo no meio são aplicados também).
     *
     * @param array<string, mixed>                $message
     * @param array<mixed>                         $views views por id, como o shell as guarda
     */
    private static function exchange(WebsocketConnection $ws, array $message, array &$views): void
    {
        $ws->sendText(json_encode($message, JSON_THROW_ON_ERROR));
        do {
            $incoming = $ws->receive();
            self::assertNotNull($incoming, 'o servidor fechou a conexão');
            $response = json_decode($incoming->buffer(), true, 64, JSON_THROW_ON_ERROR);
            foreach ($response['states'] ?? [] as $state) {
                $views[$state['#']] = $state;
            }
        } while (($response['requestId'] ?? null) !== $message['requestId']);
    }

    #[Test]
    public function purchaseOverRealHttpAndWebsocket(): void
    {
        [$appId, $webKey] = $this->loadIndex();
        $crypto = ClientCrypto::handshake($webKey);
        $ws = $this->open($appId, $crypto);
        self::assertSame('wdc', $ws->getHandshakeResponse()->getHeader('sec-websocket-protocol'), 'subprotocolo negociado');

        $views = [];
        self::exchange($ws, ['event' => [self::BROWSER . ':-1'], self::BROWSER => ['p.path' => '/'], 'requestId' => 0], $views);
        $screen = static function () use (&$views): array {
            $root = $views[$views[self::BROWSER]['contentViewId']];

            return $views[$root['contentViewId']];
        };
        $login = $screen()['#'];
        self::assertStringStartsWith('c677cda52d14:', $login);

        self::exchange($ws, ['event' => ["{$login}:1"], $login => ['p.userName' => 'fulano', 'p.password' => $crypto->cipher('fulano')], 'requestId' => 1], $views);
        $home = $screen();
        self::assertSame('Fulano de Tal', $home['nickName']);

        self::exchange($ws, ['event' => ["{$home['productsPanelViewId']}:1"], $home['productsPanelViewId'] => ['p.productId' => 1], 'requestId' => 2], $views);
        $product = $views[$screen()['contentViewId']];
        self::assertSame('Bola Wilson', $product['product']['name']);

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
        $ws = $this->open($init['appId'], null);   // sem cookie: o secret vai na 1ª mensagem

        $ws->sendText(json_encode(['secret' => $crypto->secret, 'event' => []], JSON_THROW_ON_ERROR));   // sem requestId
        $push = $ws->receive();   // o push de fundo (50 ms) traz as views da sessão nova
        self::assertNotNull($push);
        $states = json_decode($push->buffer(), true, 64, JSON_THROW_ON_ERROR)['states'] ?? [];
        self::assertContains(self::BROWSER, array_column($states, '#'));
        $ws->close();
    }

    #[Test]
    public function invalidAppId_isClosedWith4001(): void
    {
        $ws = $this->open('forjado.xyz', null);
        self::assertNull($ws->receive());
        self::assertSame(RemoteDispatcher::CLOSE_SESSION_INVALID, $ws->getCloseInfo()->getCode());
        self::assertSame('reload_required', $ws->getCloseInfo()->getReason());
    }

    #[Test]
    public function devReset_restoresTheSeed(): void
    {
        $this->server->repos->db->execute('DELETE FROM EN_PURCHASEITEM');
        $response = $this->http->request(new Request("http://{$this->base}/__dev/db-reset", 'POST'));

        self::assertSame(200, $response->getStatus());
        self::assertSame(3, (int) $this->server->repos->db->fetchValue('SELECT COUNT(*) FROM EN_PURCHASEITEM'));
    }
}
