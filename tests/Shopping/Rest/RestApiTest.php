<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Rest;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;
use Wdc\Framework\Domain\Config\AppConfig;
use Wdc\Framework\Domain\Security\PasswordUtil;
use Wdc\Shopping\Domain\ShoppingConfig;
use Wdc\Shopping\Persistence\ShoppingSchema;
use Wdc\Shopping\Server\ShoppingServer;

/**
 * A API REST de ponta a ponta, por HTTP de verdade (servidor numa porta livre, SQLite em
 * arquivo — a transação remota precisa de uma segunda conexão).
 *
 * Seed: admin (ADMIN, `data:all`) tem as compras 0 e 1 (itens 0, 1, 2); fulano e beotrano
 * (CUSTOMER) não têm nenhuma. Senha = login.
 */
final class RestApiTest extends TestCase
{
    private string $dir;

    private ShoppingServer $server;

    private HttpClient $http;

    private string $base;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wdc-rest-' . bin2hex(random_bytes(4));
        mkdir("{$this->dir}/config", 0o775, true);
        mkdir("{$this->dir}/frontend/meu.app", 0o775, true);
        $config = ShoppingConfig::configure(AppConfig::fromArray([
            'app.basedir' => '..',
            'database.path' => "{$this->dir}/shop.db",
            'security.jwt.secret' => 'segredo-rest',
            'database.remoteTransaction.maxOpenPerOwner' => '2',
        ], "{$this->dir}/config/application.toml"));

        $this->server = new ShoppingServer($config, new NullLogger());
        $this->server->start(0, '127.0.0.1');
        $this->base = 'http://127.0.0.1:' . $this->server->port();
        $this->http = HttpClientBuilder::buildDefault();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     * @return array{int, mixed} status e corpo JSON (objetos como stdClass)
     */
    private function call(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $request = new Request($this->base . $path, $method);
        if ($body !== null) {
            $request->setBody(is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $request->setHeader('content-type', 'application/json');
        }
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        $response = $this->http->request($request);
        $text = $response->getBody()->buffer();

        return [$response->getStatus(), $text === '' ? null : json_decode($text, false, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<non-empty-string, string> $extra
     * @return array<non-empty-string, string>
     */
    private static function bearer(string $token, array $extra = []): array
    {
        return ['authorization' => "Bearer {$token}"] + $extra;
    }

    /** Desafio + resposta HMAC, como um cliente REST faz. */
    private function login(string $user): stdClass
    {
        [, $challenge] = $this->call('GET', '/api/auth/challenge');
        $digest = PasswordUtil::computeHmac(PasswordUtil::hashPassword($user), $user . $challenge->nonce);
        [$status, $tokens] = $this->call('POST', '/api/auth/login', ['userName' => $user, 'digest' => $digest, 'nonce' => $challenge->nonce]);
        self::assertSame(200, $status);

        return $tokens;
    }

    private function token(string $user): string
    {
        return $this->login($user)->accessToken;
    }

    /** @return list<int> */
    private static function ids(mixed $body): array
    {
        return array_values(array_map(static fn (stdClass $e) => $e->id, $body->items));
    }

    // :: Autenticação

    #[Test]
    public function login_challengeAndHmac_givesTokensAndTheSessionKey(): void
    {
        [$status, $challenge] = $this->call('GET', '/api/auth/challenge');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3,6})?Z$/', $challenge->expiresAt);

        $tokens = $this->login('fulano');

        self::assertSame(ShoppingSchema::FULANO_ID, $tokens->userId);
        self::assertCount(3, explode('.', $tokens->accessToken), 'um JWT');
        self::assertNotEmpty($tokens->refreshToken);
        self::assertNotFalse(base64_decode($tokens->publicKey, true));

        [$status, $refreshed] = $this->call('POST', '/api/auth/refresh', ['refreshToken' => $tokens->refreshToken]);
        self::assertSame(200, $status);
        self::assertSame(ShoppingSchema::FULANO_ID, $refreshed->userId);

        self::assertEquals([200, (object) ['success' => true]], $this->call('POST', '/api/auth/logout', ['refreshToken' => $refreshed->refreshToken]));
        self::assertSame(401, $this->call('POST', '/api/auth/refresh', ['refreshToken' => $refreshed->refreshToken])[0]);
    }

    #[Test]
    public function login_wrongDigest_or_missingFields(): void
    {
        [, $challenge] = $this->call('GET', '/api/auth/challenge');

        self::assertEquals(
            [401, (object) ['error' => 'Invalid credentials']],
            $this->call('POST', '/api/auth/login', ['userName' => 'fulano', 'digest' => 'errado', 'nonce' => $challenge->nonce]),
        );
        self::assertEquals(
            [400, (object) ['error' => 'Missing required fields: userName, digest, nonce']],
            $this->call('POST', '/api/auth/login', ['userName' => 'fulano']),
        );
    }

    #[Test]
    public function protectedRoutes_needABearerToken(): void
    {
        self::assertEquals(
            [401, (object) ['error' => 'Missing or invalid Authorization header']],
            $this->call('POST', '/api/repo/product/fetch', new stdClass()),
        );
        self::assertEquals(
            [401, (object) ['error' => 'Invalid or expired token']],
            $this->call('POST', '/api/repo/product/fetch', new stdClass(), self::bearer('x.y.z')),
        );
        self::assertSame(401, $this->call('POST', '/api/tx/begin', null)[0]);
    }

    #[Test]
    public function productImage_isPublic(): void
    {
        $response = $this->http->request(new Request("{$this->base}/api/repo/product/" . ShoppingSchema::CAFETEIRA_ID . '/image'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('image/png', $response->getHeader('content-type'));
        self::assertStringStartsWith("\x89PNG", $response->getBody()->buffer());
    }

    /** Clientes REST leem 404/204 como "sem imagem"; produto inexistente precisa ser erro. */
    #[Test]
    public function productImage_ofAMissingProduct_isAnError_notNoImage(): void
    {
        self::assertEquals(
            [500, (object) ['error' => 'Failed to fetch image']],
            $this->call('GET', '/api/repo/product/999/image'),
        );
    }

    /** Clientes podem mandar a data mínima (ano -999999999, +18:00) como sentinela de projeção. */
    #[Test]
    public function purchaseProjection_withMinimumDateSentinel_isAccepted(): void
    {
        [$status, $body] = $this->call('POST', '/api/repo/purchase/fetch', [
            'projection' => ['id' => -9223372036854775807, 'buyDate' => '-999999999-01-01T00:00:00+18:00'],
        ], self::bearer($this->token('admin')));

        self::assertSame(200, $status);
        self::assertCount(2, $body->items);
        self::assertSame(['id', 'buyDate'], array_keys(get_object_vars($body->items[0])));
    }

    // :: Formato e critério

    #[Test]
    public function productFetch_wireFormat_andExpressiveCriteria(): void
    {
        $token = $this->token('fulano');

        [$status, $body] = $this->call('POST', '/api/repo/product/fetch', [
            'price' => ['p' => [['o' => 'GE', 'v' => [20.0]]]],
            'orderBy' => 'CHEAPEST_FIRST',
            'projection' => ['id' => 1, 'name' => '~', 'price' => 1.0],
        ], self::bearer($token));

        self::assertSame(200, $status);
        self::assertNotEmpty($body->items);
        $prices = array_map(static fn (stdClass $p) => $p->price, $body->items);
        self::assertSame($prices, array_values(array_filter($prices, static fn (float $p) => $p >= 20.0)));
        $sorted = $prices;
        sort($sorted);
        self::assertSame($sorted, $prices);
        self::assertSame(['id', 'name', 'price'], array_keys(get_object_vars($body->items[0])), 'só o projetado');
    }

    #[Test]
    public function fetchPage_and_count_and_fetchById(): void
    {
        $token = $this->token('fulano');

        [, $page] = $this->call('POST', '/api/repo/product/fetch-page', ['page' => 0, 'pageSize' => 2, 'orderBy' => 'OLDEST_FIRST'], self::bearer($token));
        self::assertSame(4, $page->totalItems);
        self::assertSame([0, 1], self::ids($page));

        self::assertEquals([200, (object) ['count' => 1]], $this->call('POST', '/api/repo/product/count', ['productId' => 2], self::bearer($token)));

        [$status, $product] = $this->call('GET', '/api/repo/product/2', null, self::bearer($token));
        self::assertSame(200, $status);
        self::assertSame(['id', 'name', 'price', 'description'], array_keys(get_object_vars($product)));

        self::assertEquals([404, (object) ['error' => 'Not found']], $this->call('GET', '/api/repo/product/999', null, self::bearer($token)));
    }

    #[Test]
    public function unknownOrderBy_is400(): void
    {
        [$status, $body] = $this->call('POST', '/api/repo/product/fetch', ['orderBy' => 'BY_COLOR'], self::bearer($this->token('fulano')));

        self::assertSame(400, $status);
        self::assertStringStartsWith("ordenação desconhecida: 'BY_COLOR'", $body->error);
    }

    #[Test]
    public function malformedJson_is400(): void
    {
        self::assertSame(400, $this->call('POST', '/api/repo/product/fetch', '{nope', self::bearer($this->token('fulano')))[0]);
    }

    #[Test]
    public function api_isAlsoServedUnderEachFrontendContext(): void
    {
        [$status, $body] = $this->call('POST', '/meu.app/api/repo/product/count', new stdClass(), self::bearer($this->token('fulano')));

        self::assertSame([200, 4], [$status, $body->count]);
        self::assertSame(200, $this->call('GET', '/meu.app/api/auth/challenge')[0]);
    }

    // :: Permissões (RBAC)

    #[Test]
    public function customer_cannotWriteProducts_norReadUsers(): void
    {
        $token = $this->token('fulano');

        self::assertEquals(
            [403, (object) ['error' => 'Requires product:write']],
            $this->call('POST', '/api/repo/product/delete', ['productId' => 1], self::bearer($token)),
        );
        self::assertSame(403, $this->call('POST', '/api/repo/user/fetch', new stdClass(), self::bearer($token))[0]);
    }

    #[Test]
    public function users_neverExposeThePassword(): void
    {
        $token = $this->token('admin');

        [, $users] = $this->call('POST', '/api/repo/user/fetch', ['projection' => ['id' => 1, 'userName' => '~', 'password' => '~']], self::bearer($token));
        self::assertCount(3, $users->items);
        foreach ($users->items as $user) {
            self::assertFalse(property_exists($user, 'password'));
        }
        [, $one] = $this->call('GET', '/api/repo/user/' . ShoppingSchema::FULANO_ID, null, self::bearer($token));
        self::assertSame('fulano', $one->userName);
        self::assertFalse(property_exists($one, 'password'));
    }

    #[Test]
    public function userUpdate_decryptsAPasswordSentWithTheSessionKey(): void
    {
        $tokens = $this->login('admin');
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($tokens->publicKey, 64, "\n") . "-----END PUBLIC KEY-----\n";
        self::assertTrue(openssl_public_encrypt('nova-senha', $cipher, $pem, OPENSSL_PKCS1_OAEP_PADDING, 'sha256'));

        [$status] = $this->call('POST', '/api/repo/user/update', ['id' => ShoppingSchema::FULANO_ID, 'password' => base64_encode($cipher)], self::bearer($tokens->accessToken));

        self::assertSame(200, $status);
        self::assertSame('nova-senha', $this->server->repos->users->fetchById(ShoppingSchema::FULANO_ID)?->password());
    }

    // :: Escopo por usuário (sem data:all)

    #[Test]
    public function purchases_customerSeesOnlyTheirOwn_adminSeesAll(): void
    {
        self::assertSame([0, 1], self::ids($this->call('POST', '/api/repo/purchase/fetch', ['orderBy' => 'OLDEST_FIRST'], self::bearer($this->token('admin')))[1]));

        $fulano = $this->token('fulano');
        self::assertSame([], $this->call('POST', '/api/repo/purchase/fetch', new stdClass(), self::bearer($fulano))[1]->items);
        // pedir o dono de outro não alarga a busca — nem com "or"
        $other = ['userId' => ['or' => true, 'p' => [['o' => 'EQ', 'v' => [ShoppingSchema::ADMIN_ID]]]]];
        self::assertSame([], $this->call('POST', '/api/repo/purchase/fetch', $other, self::bearer($fulano))[1]->items);
        self::assertSame(0, $this->call('POST', '/api/repo/purchase/count', $other, self::bearer($fulano))[1]->count);
        self::assertSame([], $this->call('POST', '/api/repo/purchase-item/fetch', $other, self::bearer($fulano))[1]->items);
    }

    #[Test]
    public function purchaseInsert_belongsToTheCaller_withItems_andComesBackNested(): void
    {
        $fulano = $this->token('fulano');

        [$status, $result] = $this->call('POST', '/api/repo/purchase/insert', [
            'buyDate' => '2024-05-01T12:00:00Z',
            'user' => ['id' => ShoppingSchema::ADMIN_ID],   // ignorado: a compra é de quem chama
            'items' => [['amount' => 2, 'price' => 10.0, 'product' => ['id' => ShoppingSchema::BOLA_WILSON_ID]]],
        ], self::bearer($fulano));
        self::assertSame(200, $status);
        self::assertTrue($result->success);

        [, $mine] = $this->call('POST', '/api/repo/purchase/fetch', new stdClass(), self::bearer($fulano));
        self::assertSame([$result->id], self::ids($mine));
        $purchase = $mine->items[0];
        self::assertSame('2024-05-01T12:00:00Z', $purchase->buyDate);
        self::assertSame(ShoppingSchema::FULANO_ID, $purchase->user->id);
        self::assertSame(2, $purchase->items[0]->amount);
        self::assertSame(ShoppingSchema::BOLA_WILSON_ID, $purchase->items[0]->product->id);
        self::assertFalse(property_exists($purchase->items[0], 'purchaseId'));

        [, $simple] = $this->call('POST', '/api/repo/purchase/fetch', ['includeItems' => false], self::bearer($fulano));
        self::assertFalse(property_exists($simple->items[0], 'items'));
    }

    /** Um cliente sem data:all não lê nem grava compras e itens de outro usuário. */
    #[Test]
    public function customer_cannotTouchSomeoneElsesPurchaseOrItems(): void
    {
        $fulano = self::bearer($this->token('fulano'));
        $adminPurchase = ShoppingSchema::ADMIN_FIRST_PURCHASE_ID;
        $adminItem = ShoppingSchema::ADMIN_SECOND_PURCHASE_ITEM0_ID;
        $denied = [403, (object) ['error' => "Cannot modify other user's data"]];

        self::assertEquals($denied, $this->call('POST', '/api/repo/purchase/update', ['id' => $adminPurchase, 'buyDate' => '2020-01-01T00:00:00Z'], $fulano));
        self::assertSame(404, $this->call('GET', "/api/repo/purchase/{$adminPurchase}", null, $fulano)[0]);
        self::assertSame(404, $this->call('POST', '/api/repo/purchase/fetch-by-id', ['id' => $adminPurchase, 'projection' => ['id' => 1, 'buyDate' => '2000-01-01T00:00:00Z']], $fulano)[0]);

        self::assertEquals($denied, $this->call('POST', '/api/repo/purchase-item/insert', ['purchaseId' => $adminPurchase, 'amount' => 1, 'price' => 1.0, 'product' => ['id' => 0]], $fulano));
        self::assertEquals($denied, $this->call('POST', '/api/repo/purchase-item/update', ['id' => $adminItem, 'amount' => 99], $fulano));
        self::assertSame(404, $this->call('GET', "/api/repo/purchase-item/{$adminItem}", null, $fulano)[0]);
        self::assertSame(404, $this->call('POST', '/api/repo/purchase-item/fetch-by-id', ['id' => $adminItem], $fulano)[0]);
        self::assertSame(0, $this->call('POST', '/api/repo/purchase-item/fetch-page', ['page' => 0, 'pageSize' => 10], $fulano)[1]->totalItems);

        // e nada mudou
        $admin = self::bearer($this->token('admin'));
        [, $item] = $this->call('GET', "/api/repo/purchase-item/{$adminItem}", null, $admin);
        self::assertNotSame(99, $item->amount);
        self::assertSame(3, $this->call('POST', '/api/repo/purchase-item/count', new stdClass(), $admin)[1]->count);
    }

    #[Test]
    public function customer_canUpdateTheirOwnPurchaseItems(): void
    {
        $fulano = self::bearer($this->token('fulano'));
        [, $created] = $this->call('POST', '/api/repo/purchase/insert', [
            'buyDate' => '2024-05-01T12:00:00Z',
            'items' => [['amount' => 1, 'price' => 10.0, 'product' => ['id' => 1]]],
        ], $fulano);
        [, $items] = $this->call('POST', '/api/repo/purchase-item/fetch', ['purchaseId' => $created->id], $fulano);
        $itemId = $items->items[0]->id;

        self::assertSame(200, $this->call('POST', '/api/repo/purchase-item/update', ['id' => $itemId, 'amount' => 5], $fulano)[0]);
        self::assertSame(5, $this->call('GET', "/api/repo/purchase-item/{$itemId}", null, $fulano)[1]->amount);
        // mover o item para a compra de outro, não
        self::assertSame(403, $this->call('POST', '/api/repo/purchase-item/update', ['id' => $itemId, 'purchaseId' => ShoppingSchema::ADMIN_FIRST_PURCHASE_ID], $fulano)[0]);
    }

    // :: Transação remota

    #[Test]
    public function remoteTransaction_writesBecomeVisibleOnlyOnCommit(): void
    {
        $fulano = $this->token('fulano');
        [$status, $begin] = $this->call('POST', '/api/tx/begin', null, self::bearer($fulano));
        self::assertSame(200, $status);
        $tx = self::bearer($fulano, ['x-tx-id' => $begin->txId]);

        $order = ['buyDate' => '2024-05-01T12:00:00Z', 'items' => [['amount' => 1, 'price' => 1.0, 'product' => ['id' => 0]]]];
        [$first, $a] = $this->call('POST', '/api/repo/purchase/insert', $order, $tx);
        [$second, $b] = $this->call('POST', '/api/repo/purchase/insert', $order, $tx);
        self::assertSame([200, 200], [$first, $second]);
        self::assertNotSame($a->id, $b->id);

        self::assertSame(0, $this->call('POST', '/api/repo/purchase/count', new stdClass(), self::bearer($fulano))[1]->count, 'ainda não commitado');
        self::assertSame('open', $this->call('GET', '/api/tx/status', null, $tx)[1]->status);

        // escrita sem o cabeçalho com a transação aberta: o cabeçalho se perdeu → 409
        self::assertSame(409, $this->call('POST', '/api/repo/purchase/insert', $order, self::bearer($fulano))[0]);

        self::assertEquals([200, (object) ['status' => 'committed']], $this->call('POST', '/api/tx/commit', null, $tx));
        self::assertSame(2, $this->call('POST', '/api/repo/purchase/count', new stdClass(), self::bearer($fulano))[1]->count);

        self::assertSame(200, $this->call('POST', '/api/tx/commit', null, $tx)[0], 'repetir o commit é no-op');
        self::assertSame(409, $this->call('POST', '/api/tx/rollback', null, $tx)[0], 'o desfecho oposto, não');
        self::assertSame('committed', $this->call('GET', '/api/tx/status', null, $tx)[1]->status);
    }

    #[Test]
    public function remoteTransaction_rollback_owner_andLimits(): void
    {
        $fulano = $this->token('fulano');
        [, $begin] = $this->call('POST', '/api/tx/begin', null, self::bearer($fulano));
        $tx = self::bearer($fulano, ['x-tx-id' => $begin->txId]);
        $this->call('POST', '/api/repo/purchase/insert', ['buyDate' => '2024-05-01T12:00:00Z'], $tx);

        // outro usuário não usa o txId
        $beotrano = $this->token('beotrano');
        self::assertSame(403, $this->call('POST', '/api/tx/commit', null, self::bearer($beotrano, ['x-tx-id' => $begin->txId]))[0]);
        self::assertSame(403, $this->call('POST', '/api/repo/purchase/insert', ['buyDate' => '2024-05-01T12:00:00Z'], self::bearer($beotrano, ['x-tx-id' => $begin->txId]))[0]);

        self::assertEquals([200, (object) ['status' => 'rolledback']], $this->call('POST', '/api/tx/rollback', null, $tx));
        self::assertSame(0, $this->call('POST', '/api/repo/purchase/count', new stdClass(), self::bearer($fulano))[1]->count);

        // teto por dono (2, na configuração deste teste)
        $this->call('POST', '/api/tx/begin', null, self::bearer($fulano));
        $this->call('POST', '/api/tx/begin', null, self::bearer($fulano));
        self::assertSame(429, $this->call('POST', '/api/tx/begin', null, self::bearer($fulano))[0]);

        self::assertEquals(
            [403, (object) ['error' => 'Cabeçalho X-Tx-Id ausente']],
            $this->call('POST', '/api/tx/commit', null, self::bearer($fulano)),
        );
        self::assertSame(500, $this->call('POST', '/api/tx/commit', null, self::bearer($fulano, ['x-tx-id' => 'nao-existe']))[0]);
    }
}
