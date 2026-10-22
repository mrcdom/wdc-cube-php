<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\RemoteHost;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Concurrent\ManualScheduler;
use Wdc\Framework\Commons\Security\Rsa;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\RemoteDispatcher;
use Wdc\Shopping\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Shopping\Persistence\ShoppingRepositories;
use Wdc\Tests\Support\TestDatabase;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartPresenter;
use Wdc\Shopping\Presentation\ShoppingContext;
use Wdc\Shopping\Presentation\StorageKeys;
use Wdc\Shopping\RemoteHost\ShoppingRemoteHost;

/**
 * O protocolo do shell React de ponta a ponta, sem rede: um "browser" falso conversa com o
 * host pelo dispatcher, exatamente com as mensagens que o shell manda.
 */
final class RemoteProtocolTest extends TestCase
{
    private const string ROOT = 'f2d345c4a610';
    private const string LOGIN = 'c677cda52d14';
    private const string HOME = '473dbdd7a36a';
    private const string PRODUCTS = 'a1b2c3d4e5f6';
    private const string PURCHASES = 'b3c4d5e6f7a8';
    private const string PRODUCT = '48b693f67410';
    private const string CART = '7eb485e5f843';
    private const string RECEIPT = 'e8d0bd8ae3bc';

    private static ?RemoteAppSecurity $security = null;

    private float $nowMs = 1_000_000.0;

    private ManualScheduler $scheduler;

    private ShoppingContext $context;

    private ShoppingRemoteHost $host;

    private RemoteDispatcher $dispatcher;

    protected function setUp(): void
    {
        if (self::$security === null) {
            $keys = RemoteAppSecurity::generateDevKeys();
            self::$security = new RemoteAppSecurity(Rsa::fromBase36($keys['rsaPublicKey'], $keys['rsaPrivateKey']), $keys['signPublicKey'], $keys['signPrivateKey']);
        }
        $repos = TestDatabase::repositories();
        $this->scheduler = new ManualScheduler();
        $this->context = ShoppingContext::of($repos, $this->scheduler, new AuthenticationServiceImpl($repos->users, 'jwt-secret'));
        $this->host = new ShoppingRemoteHost($this->context, self::$security, maxSessions: 2, clock: fn (): float => $this->nowMs);
        $this->host->registry->init($this->scheduler);
        $this->dispatcher = new RemoteDispatcher($this->host->registry, self::$security);
    }

    /** @param array<string, array<string, string>>|null $storage */
    private function newBrowser(?array $storage = null): FakeBrowser
    {
        $browser = $storage === null
            ? new FakeBrowser($this->host, $this->dispatcher)
            : new FakeBrowser($this->host, $this->dispatcher, $storage);
        $browser->open();

        return $browser;
    }

    private function loggedIn(): FakeBrowser
    {
        $browser = $this->newBrowser();
        $browser->start();
        $browser->submit($browser->screen()['#'], 1, ['p.userName' => 'admin', 'p.password' => $browser->cipher('admin')]);
        $browser->take();

        return $browser;
    }

    #[Test]
    public function boot_withoutHash_landsOnLogin(): void
    {
        $browser = $this->newBrowser();
        $browser->start('/');

        [$response] = $browser->take();
        self::assertSame(0, $response['requestId']);
        self::assertMatchesRegularExpression('/^login\?sign=[0-9A-Za-z]+$/', $response['uri']);

        $root = $browser->state($browser->state(FakeBrowser::BROWSER)['contentViewId']);
        self::assertSame(self::ROOT, FakeBrowser::classOf($root['#']));
        self::assertSame(['#' => $browser->screen()['#'], 'errorCode' => 0, 'loading' => false], $browser->screen(), 'null omitido, int e bool sempre');
        self::assertSame(self::LOGIN, FakeBrowser::classOf($browser->screen()['#']));
        self::assertSame(
            ['#' => FakeBrowser::BROWSER, 'instanceId' => FakeBrowser::BROWSER, 'alertId' => 0, 'alertArgs' => [], 'contentViewId' => $root['#']],
            $browser->state(FakeBrowser::BROWSER),
        );
    }

    #[Test]
    public function login_overTheEncryptedChannel_reachesHome_andStoresTheToken(): void
    {
        $browser = $this->newBrowser();
        $browser->start();
        $login = $browser->screen()['#'];

        $browser->submit($login, 1, ['p.userName' => 'admin', 'p.password' => $browser->cipher('admin')]);

        $home = $browser->screen();
        self::assertSame(self::HOME, FakeBrowser::classOf($home['#']));
        self::assertSame('João da Silva', $home['nickName']);
        self::assertSame(0, $home['cartItemCount']);
        self::assertArrayNotHasKey('contentViewId', $home, 'home sem conteúdo aberto');
        self::assertSame(self::PRODUCTS, FakeBrowser::classOf($home['productsPanelViewId']));
        self::assertSame(self::PURCHASES, FakeBrowser::classOf($home['purchasesPanelViewId']));
        self::assertMatchesRegularExpression('/^home\?userId=0&sign=[0-9A-Za-z]+$/', (string) $browser->uri);

        $token = $browser->storage['persistent-secure'][StorageKeys::AUTH_TOKEN] ?? '';
        self::assertCount(3, explode('.', $token), 'o JWT chegou cifrado e o cliente o abriu');
        self::assertContains($login, $this->releasedAfterPing($browser), 'a view do login foi liberada');
    }

    #[Test]
    public function wrongPassword_staysOnLogin_withTheMessage(): void
    {
        $browser = $this->newBrowser();
        $browser->start();
        $browser->submit($browser->screen()['#'], 1, ['p.userName' => 'admin', 'p.password' => $browser->cipher('errada')]);

        self::assertSame('Usuário ou senha não reconhecido!', $browser->screen()['errorMessage']);
        self::assertSame('admin', $browser->screen()['userName']);
    }

    #[Test]
    public function fullPurchase_overTheProtocol(): void
    {
        $browser = $this->loggedIn();
        $home = $browser->screen();

        $browser->submit($home['purchasesPanelViewId'], 3, ['p.capacity' => 5]);
        $purchases = $browser->state($home['purchasesPanelViewId']);
        self::assertSame(2, $purchases['totalCount']);
        self::assertSame([1, 0], array_column($purchases['purchases'], 'id'));
        self::assertIsInt($purchases['purchases'][0]['date'], 'data em milissegundos');

        $products = $browser->state($home['productsPanelViewId']);
        self::assertSame(['id' => 3, 'image' => 'image/product/3.png', 'name' => 'Pen Drive 2GB', 'description' => 'unknown', 'price' => 16.0], $products['products'][3]);

        $browser->submit($home['productsPanelViewId'], 1, ['p.productId' => (string) Seed::PEN_DRIVE2GB_ID]);
        $product = $browser->state($browser->screen()['contentViewId']);
        self::assertSame(self::PRODUCT, FakeBrowser::classOf($product['#']));
        self::assertStringStartsWith('Ideal para transporte', $product['product']['description']);
        self::assertMatchesRegularExpression('/^product\?userId=0&productId=3&sign=/', (string) $browser->uri);

        $browser->submit($product['#'], 2, ['p.quantity' => 2, 'quantity' => 2]);
        $cart = $browser->state($browser->screen()['contentViewId']);
        self::assertSame(self::CART, FakeBrowser::classOf($cart['#']));
        self::assertSame(2, $cart['items'][0]['quantity']);
        self::assertSame(2, $browser->screen()['cartItemCount']);

        $browser->submit($cart['#'], 1);
        $receipt = $browser->state($browser->screen()['contentViewId']);
        self::assertSame(self::RECEIPT, FakeBrowser::classOf($receipt['#']));
        self::assertTrue($receipt['notifySuccess']);
        self::assertSame(32.0, $receipt['receipt']['total']);
        self::assertSame(0, $browser->screen()['cartItemCount']);
        self::assertContains($product['#'], $this->releasedAfterPing($browser), 'a view do produto foi liberada');
    }

    /** @return list<string> */
    private function releasedAfterPing(FakeBrowser $browser): array
    {
        $browser->send(['ping' => true], withRequestId: false);

        return $browser->released;
    }

    #[Test]
    public function everyRequestIsAnswered_evenWithNothingToSay(): void
    {
        $browser = $this->loggedIn();

        $browser->submit(FakeBrowser::BROWSER, 2);   // keep-alive (requests 0 e 1 foram o boot e o login)

        self::assertSame([['requestId' => 2]], $browser->take());
    }

    #[Test]
    public function repeatedOrMissingRequestId_isDiscarded(): void
    {
        $browser = $this->loggedIn();
        $home = $browser->screen()['#'];

        $browser->sendRaw(json_encode(['event' => ["{$home}:2"], 'requestId' => 1], JSON_THROW_ON_ERROR));   // id já usado
        $browser->sendRaw(json_encode(['event' => ["{$home}:2"]], JSON_THROW_ON_ERROR));                    // sem id

        self::assertSame([], $browser->take());
        self::assertArrayNotHasKey('contentViewId', $browser->screen(), 'o carrinho não abriu');
    }

    #[Test]
    public function tamperedHistoryPath_isIgnored_andTheCurrentStateIsReaffirmed(): void
    {
        $browser = $this->loggedIn();
        $before = $browser->uri;

        $browser->submit(FakeBrowser::BROWSER, -2, ['p.path' => 'cart?userId=0&sign=forjada']);

        self::assertSame($before, $browser->uri, 'servidor reenvia a URL atual');
        self::assertArrayNotHasKey('contentViewId', $browser->screen());
    }

    #[Test]
    public function signedHistoryPath_fromTheServer_navigatesBack(): void
    {
        $browser = $this->loggedIn();
        $browser->submit($browser->screen()['productsPanelViewId'], 1, ['p.productId' => 1]);
        $productUri = (string) $browser->uri;
        $browser->submit($browser->state($browser->screen()['contentViewId'])['#'], 1);   // volta para a home

        $browser->submit(FakeBrowser::BROWSER, -2, ['p.path' => $productUri]);   // botão "voltar" do browser

        $product = $browser->state($browser->screen()['contentViewId']);
        self::assertSame(Seed::BOLA_WILSON_ID, $product['product']['id']);
    }

    #[Test]
    public function eventForAnUnknownView_resendsEverything(): void
    {
        $browser = $this->loggedIn();

        $browser->submit('a1b2c3d4e5f6:999', 1);

        [$response] = $browser->take();
        self::assertGreaterThanOrEqual(5, count($response['states'] ?? []), 'browser, root, home e os dois painéis');
    }

    #[Test]
    public function failingEvent_showsTheUnexpectedErrorAlert(): void
    {
        $browser = $this->loggedIn();

        $browser->submit($browser->screen()['#'], 99);

        $alert = $browser->state(FakeBrowser::BROWSER);
        self::assertSame(-1, $alert['alertId']);
        self::assertSame('submitting event', $alert['alertArgs'][0]);
        self::assertStringContainsString('eventCode(99) not handled', $alert['alertArgs'][1]);
        self::assertSame(['id' => -1, 'args' => $alert['alertArgs']], $alert['alertMessage'], 'o campo que o React lê');

        $browser->submit(FakeBrowser::BROWSER, 1);   // OK do alerta
        $after = $browser->state(FakeBrowser::BROWSER);
        self::assertSame([], $after['alertArgs']);
        self::assertArrayNotHasKey('alertMessage', $after, 'sem alerta, o React não mostra nada');
    }

    #[Test]
    public function activeViews_areListedOnPingEveryFiveMinutes(): void
    {
        $browser = $this->loggedIn();
        $browser->send(['ping' => true], withRequestId: false);
        self::assertSame([['releasedViews' => [$browser->released[0]]]], $browser->take(), 'o 1º ping avisa que o login foi liberado');
        $browser->send(['ping' => true], withRequestId: false);
        self::assertSame([], $browser->take(), 'nada a dizer');

        $this->nowMs += 5 * 60 * 1000;
        $browser->send(['ping' => true], withRequestId: false);

        [$message] = $browser->take();
        self::assertEqualsCanonicalizing(array_keys($browser->views), $message['activeViews']);
    }

    #[Test]
    public function backgroundPush_deliversTimerChanges_withoutRequestId(): void
    {
        $browser = $this->loggedIn();
        $browser->submit($browser->screen()['productsPanelViewId'], 1, ['p.productId' => 0]);
        $browser->submit($browser->state($browser->screen()['contentViewId'])['#'], 2, ['p.quantity' => 1]);
        $cart = $browser->state($browser->screen()['contentViewId'])['#'];
        $browser->submit($cart, 4, ['p.productId' => 0, 'p.quantity' => 0]);
        self::assertSame(1, $browser->state($cart)['errorCode']);
        $browser->take();

        $this->scheduler->advance(CartPresenter::ERROR_CLEAR_DELAY_SECONDS + 0.1);
        $browser->receive();

        $pushes = $browser->take();
        self::assertNotEmpty($pushes);
        self::assertArrayNotHasKey('requestId', $pushes[0]);
        self::assertSame(0, $browser->state($cart)['errorCode'], 'o aviso sumiu sozinho, empurrado pelo servidor');
    }

    #[Test]
    public function reconnection_resendsTheWholeState(): void
    {
        $browser = $this->loggedIn();
        $browser->submit($browser->screen()['productsPanelViewId'], 1, ['p.productId' => 2]);
        $browser->send(['ping' => true], withRequestId: false);   // o cliente fica sabendo das views liberadas
        $browser->take();
        $known = array_keys($browser->views);
        $uri = $browser->uri;

        $old = $browser->reconnect();   // queda de rede / F5 com a sessão viva
        $this->dispatcher->onClose($browser->appId, $old);   // o fechamento da conexão velha chega depois
        $this->host->registry->flushAll();
        $browser->receive();

        [$push] = $browser->take();
        self::assertSame($uri, $push['uri']);
        self::assertEqualsCanonicalizing($known, array_column($push['states'], '#'));
        self::assertSame(1, $this->host->registry->size(), 'o close da conexão velha foi ignorado');
    }

    #[Test]
    public function anonymousSession_isReleasedOnClose_authenticatedOneWaitsForExpiry(): void
    {
        $anonymous = $this->newBrowser();
        $anonymous->start();
        $logged = $this->loggedIn();
        self::assertSame(2, $this->host->registry->size());

        $anonymous->close();
        $logged->close();
        self::assertNotNull($this->host->registry->get($logged->appId));
        self::assertNull($this->host->registry->get($anonymous->appId));

        $this->nowMs += 181_000;
        $this->host->registry->removeExpired();
        self::assertSame(0, $this->host->registry->size());
    }

    #[Test]
    public function invalidAppId_isRejectedWith4001(): void
    {
        $connection = new RecordingConnection();
        $this->dispatcher->onOpen('forjado.abc', 'x', $connection);

        self::assertSame([RemoteDispatcher::CLOSE_SESSION_INVALID, 'reload_required'], [$connection->closeCode, $connection->closeReason]);
    }

    #[Test]
    public function withoutCookie_theFirstMessageMustCarryTheSecret(): void
    {
        $browser = new FakeBrowser($this->host, $this->dispatcher);
        $browser->open(withCookie: false);
        $browser->send(['event' => [FakeBrowser::BROWSER . ':-1'], FakeBrowser::BROWSER => ['p.path' => '/']]);

        self::assertSame(RemoteDispatcher::CLOSE_SESSION_INVALID, $browser->connection->closeCode);
        self::assertSame(0, $this->host->registry->size());
    }

    #[Test]
    public function sessionLimit_isEnforcedWith4003(): void
    {
        $this->newBrowser()->start();
        $this->newBrowser()->start();

        $third = $this->newBrowser();
        $third->start();

        self::assertSame([RemoteDispatcher::CLOSE_CAPACITY_EXCEEDED, 'capacity_exceeded'], [$third->connection->closeCode, $third->connection->closeReason]);
    }

    #[Test]
    public function persistentToken_inTheBrowserStorage_logsTheNextSessionInAutomatically(): void
    {
        $first = $this->loggedIn();
        $first->close();
        $this->nowMs += 181_000;
        $this->host->registry->removeExpired();

        $next = $this->newBrowser($first->storage);   // mesmo browser, outra aba / outro dia
        $next->start();

        self::assertSame(self::HOME, FakeBrowser::classOf($next->screen()['#']));
        self::assertSame('João da Silva', $next->screen()['nickName']);
    }

    #[Test]
    public function clientIp_comesFromTheConnection(): void
    {
        $browser = $this->loggedIn();
        $app = $this->host->registry->get($browser->appId);

        self::assertSame('10.0.0.7', $app?->remote()->clientIp());
    }
}
