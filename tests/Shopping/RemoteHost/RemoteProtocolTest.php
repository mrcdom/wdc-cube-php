<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\RemoteHost;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\ManualScheduler;
use Wdc\Framework\Commons\Security\Rsa;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\RemoteDispatcher;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Shopping\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Framework\Persistence\Database;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Shopping\Presentation\Presenter\Restricted\Cart\CartPresenter;
use Wdc\Shopping\Presentation\ShoppingContext;
use Wdc\Shopping\Presentation\StorageKeys;
use Wdc\Shopping\RemoteHost\ShoppingRemoteHost;
use Wdc\Tests\Support\TestDatabase;

/**
 * The React shell protocol end to end, without a network: a fake "browser" talks to the
 * host through the dispatcher, with exactly the messages the shell sends.
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
        TestDatabase::open();
        $this->scheduler = new ManualScheduler();
        Beans::set(AuthenticationService::class, new AuthenticationServiceImpl('jwt-secret'));
        $this->context = new ShoppingContext($this->scheduler);
        $this->host = new ShoppingRemoteHost($this->context, self::$security, maxSessions: 2, clock: fn (): float => $this->nowMs);
        $this->host->registry->init($this->scheduler);
        $this->dispatcher = new RemoteDispatcher($this->host->registry, self::$security);
    }

    protected function tearDown(): void
    {
        Beans::clear();
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
        self::assertSame(['#' => $browser->screen()['#'], 'errorCode' => 0, 'loading' => false], $browser->screen(), 'null omitted, int and bool always present');
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
        self::assertSame('John Smith', $home['nickName']);
        self::assertSame(0, $home['cartItemCount']);
        self::assertArrayNotHasKey('contentViewId', $home, 'home with no content open');
        self::assertSame(self::PRODUCTS, FakeBrowser::classOf($home['productsPanelViewId']));
        self::assertSame(self::PURCHASES, FakeBrowser::classOf($home['purchasesPanelViewId']));
        self::assertMatchesRegularExpression('/^home\?rt-user=0&sign=[0-9A-Za-z]+$/', (string) $browser->uri);

        $token = $browser->storage['persistent-secure'][StorageKeys::AUTH_TOKEN] ?? '';
        self::assertCount(3, explode('.', $token), 'the JWT arrived encrypted and the client opened it');
        self::assertContains($login, $this->releasedAfterPing($browser), 'the login view was released');
    }

    #[Test]
    public function wrongPassword_staysOnLogin_withTheMessage(): void
    {
        $browser = $this->newBrowser();
        $browser->start();
        $browser->submit($browser->screen()['#'], 1, ['p.userName' => 'admin', 'p.password' => $browser->cipher('wrong')]);

        self::assertSame('Username or password not recognized!', $browser->screen()['errorMessage']);
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
        self::assertIsInt($purchases['purchases'][0]['date'], 'date in milliseconds');

        $products = $browser->state($home['productsPanelViewId']);
        self::assertSame(['id' => 3, 'image' => 'image/product/3.png', 'name' => 'Flash drive 2GB', 'description' => 'unknown', 'price' => 16.0], $products['products'][3]);

        $browser->submit($home['productsPanelViewId'], 1, ['p.productId' => (string) Seed::FLASH_DRIVE2GB_ID]);
        $product = $browser->state($browser->screen()['contentViewId']);
        self::assertSame(self::PRODUCT, FakeBrowser::classOf($product['#']));
        self::assertStringStartsWith('Ideal for carrying', $product['product']['description']);
        self::assertMatchesRegularExpression('/^product\?rt-user=0&pr-id=3&sign=/', (string) $browser->uri);

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
        self::assertContains($product['#'], $this->releasedAfterPing($browser), 'the product view was released');
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

        $browser->submit(FakeBrowser::BROWSER, 2);   // keep-alive (requests 0 and 1 were the boot and the login)

        self::assertSame([['requestId' => 2]], $browser->take());
    }

    #[Test]
    public function repeatedOrMissingRequestId_isDiscarded(): void
    {
        $browser = $this->loggedIn();
        $home = $browser->screen()['#'];

        $browser->sendRaw(json_encode(['event' => ["{$home}:2"], 'requestId' => 1], JSON_THROW_ON_ERROR));   // id already used
        $browser->sendRaw(json_encode(['event' => ["{$home}:2"]], JSON_THROW_ON_ERROR));                    // no id

        self::assertSame([], $browser->take());
        self::assertArrayNotHasKey('contentViewId', $browser->screen(), 'the cart did not open');
    }

    #[Test]
    public function tamperedHistoryPath_isIgnored_andTheCurrentStateIsReaffirmed(): void
    {
        $browser = $this->loggedIn();
        $before = $browser->uri;

        $browser->submit(FakeBrowser::BROWSER, -2, ['p.path' => 'cart?rt-user=0&sign=forged']);

        self::assertSame($before, $browser->uri, 'the server resends the current URL');
        self::assertArrayNotHasKey('contentViewId', $browser->screen());
    }

    #[Test]
    public function signedHistoryPath_fromTheServer_navigatesBack(): void
    {
        $browser = $this->loggedIn();
        $browser->submit($browser->screen()['productsPanelViewId'], 1, ['p.productId' => 1]);
        $productUri = (string) $browser->uri;
        $browser->submit($browser->state($browser->screen()['contentViewId'])['#'], 1);   // back to the home

        $browser->submit(FakeBrowser::BROWSER, -2, ['p.path' => $productUri]);   // the browser's "back" button

        $product = $browser->state($browser->screen()['contentViewId']);
        self::assertSame(Seed::WILSON_BALL_ID, $product['product']['id']);
    }

    #[Test]
    public function eventForAnUnknownView_resendsEverything(): void
    {
        $browser = $this->loggedIn();

        $browser->submit('a1b2c3d4e5f6:999', 1);

        [$response] = $browser->take();
        self::assertGreaterThanOrEqual(5, count($response['states'] ?? []), 'browser, root, home and both panels');
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
        self::assertSame(['id' => -1, 'args' => $alert['alertArgs']], $alert['alertMessage'], 'the field React reads');

        $browser->submit(FakeBrowser::BROWSER, 1);   // alert OK
        $after = $browser->state(FakeBrowser::BROWSER);
        self::assertSame([], $after['alertArgs']);
        self::assertArrayNotHasKey('alertMessage', $after, 'with no alert, React shows nothing');
    }

    #[Test]
    public function activeViews_areListedOnPingEveryFiveMinutes(): void
    {
        $browser = $this->loggedIn();
        $browser->send(['ping' => true], withRequestId: false);
        self::assertSame([['releasedViews' => [$browser->released[0]]]], $browser->take(), 'the 1st ping reports that the login was released');
        $browser->send(['ping' => true], withRequestId: false);
        self::assertSame([], $browser->take(), 'nothing to say');

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
        self::assertSame(0, $browser->state($cart)['errorCode'], 'the warning went away by itself, pushed by the server');
    }

    #[Test]
    public function reconnection_resendsTheWholeState(): void
    {
        $browser = $this->loggedIn();
        $browser->submit($browser->screen()['productsPanelViewId'], 1, ['p.productId' => 2]);
        $browser->send(['ping' => true], withRequestId: false);   // the client learns about the released views
        $browser->take();
        $known = array_keys($browser->views);
        $uri = $browser->uri;

        $old = $browser->reconnect();   // network drop / F5 with the session alive
        $this->dispatcher->onClose($browser->appId, $old);   // the old connection's close arrives later
        $this->host->registry->flushAll();
        $browser->receive();

        [$push] = $browser->take();
        self::assertSame($uri, $push['uri']);
        self::assertEqualsCanonicalizing($known, array_column($push['states'], '#'));
        self::assertSame(1, $this->host->registry->size(), 'the old connection close was ignored');
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
        $this->dispatcher->onOpen('forged.abc', 'x', $connection);

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

        $next = $this->newBrowser($first->storage);   // same browser, another tab / another day
        $next->start();

        self::assertSame(self::HOME, FakeBrowser::classOf($next->screen()['#']));
        self::assertSame('John Smith', $next->screen()['nickName']);
    }

    #[Test]
    public function clientIp_comesFromTheConnection(): void
    {
        $browser = $this->loggedIn();
        $app = $this->host->registry->get($browser->appId);

        self::assertSame('10.0.0.7', $app?->remote()->clientIp());
    }
}
