<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence\Rest;

use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Closure;
use Psr\Log\LoggerInterface;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Exception\TransactionConflictException;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Framework\Persistence\Transaction\RemoteTransactionCoordinator;
use Wdc\Framework\Rest\RestFilter;
use Wdc\Framework\Rest\RestHandler;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Store\Persistence\Rest\Security\SecurityEnforcer;
use Wdc\Store\Persistence\Rest\Security\SecurityFilter;

/**
 * The repository REST API: clients that do not use the WebSocket (native apps, SPAs) access
 * the data through it.
 *
 * | route                                        | what                                    |
 * |----------------------------------------------|-----------------------------------------|
 * | `/api/auth/{challenge,login,refresh,logout}` | HMAC challenge login, JWT               |
 * | `/api/repo/<entity>/<operation>`             | user, product, purchase, purchase-item  |
 * | `/api/repo/product/{id}/image`               | image (public GET, protected PUT)       |
 * | `/api/tx/{begin,commit,rollback,status}`     | client-driven remote transaction        |
 *
 * With an AuthenticationService, `/api/repo/*` and `/api/tx/*` require a Bearer JWT. Without it
 * (security off), the auth routes do not even exist and nothing is checked.
 */
final class RepositoryApiRoutes
{
    private readonly SecurityEnforcer $security;

    private readonly ?RestFilter $filter;

    /** @var list<ApiController> */
    private readonly array $controllers;

    private readonly ?AuthenticationService $auth;

    private readonly ?TransactionService $transactions;

    public function __construct(
        private readonly ?RemoteTransactionCoordinator $coordinator,
        private readonly LoggerInterface $logger,
    ) {
        $auth = $this->auth = Beans::find(AuthenticationService::class);
        $this->transactions = Beans::find(TransactionService::class);
        $this->security = new SecurityEnforcer($auth);
        $this->filter = $auth === null ? null : new SecurityFilter($auth);
        $this->controllers = [
            new UserApiController($this->security, $logger),
            new ProductApiController($this->security, $logger),
            new PurchaseApiController($this->security),
            new PurchaseItemApiController($this->security),
        ];
    }

    /**
     * Registers the routes under a prefix: '' (root) or a frontend's (`/{ctx:...}`), so that
     * an SPA served under a context calls the API without CORS.
     */
    public function configure(Router $router, string $prefix = ''): void
    {
        if ($this->auth !== null) {
            (new AuthApiController($this->auth))->routes($prefix, function (string $method, string $path, Closure $endpoint) use ($router): void {
                $router->addRoute($method, $path, new RestHandler($endpoint, null, $this->logger));
            });
        }
        foreach ($this->routes($prefix) as [$method, $path, $handler]) {
            $router->addRoute($method, $path, $handler);
        }
    }

    /**
     * The protected routes (`/api/repo/*`, `/api/tx/*`), without an HTTP server — also for tests.
     *
     * @return list<array{0: string, 1: string, 2: RestHandler}>
     */
    public function routes(string $prefix = ''): array
    {
        $routes = [];
        $add = function (string $method, string $path, Closure $endpoint, bool $transactional = false) use (&$routes): void {
            $limit = str_ends_with($path, '/image') ? 16 * RestHandler::DEFAULT_BODY_LIMIT : RestHandler::DEFAULT_BODY_LIMIT;
            $routes[] = [$method, $path, new RestHandler($transactional ? $this->transactional($endpoint) : $endpoint, $this->filter, $this->logger, $limit)];
        };
        foreach ($this->controllers as $controller) {
            $controller->routes($prefix, $add);
        }
        (new TxApiController($this->security, $this->coordinator))->routes($prefix, $add);

        return $routes;
    }

    /**
     * Wraps a **write** endpoint in a transaction:
     *
     * - with `X-Tx-Id`: joins the client's remote transaction (resume/suspend), without commit — the
     *   boundary belongs to the client;
     * - without `X-Tx-Id`, but with a remote transaction open for the requester: the header got lost
     *   on the way → 409, instead of writing outside the transaction (it would break the block's atomicity);
     * - otherwise, one transaction per request (commit on return, rollback on exception).
     *
     * @param Closure(RestRequest): Response $endpoint
     * @return Closure(RestRequest): Response
     */
    public function transactional(Closure $endpoint): Closure
    {
        return function (RestRequest $request) use ($endpoint): Response {
            $txId = $request->header(TxApiController::TX_HEADER);
            if ($txId !== null && trim($txId) !== '' && $this->coordinator !== null) {
                $this->coordinator->resume($txId, TxApiController::currentOwnerKey($request));
                try {
                    return $endpoint($request);
                } finally {
                    $this->coordinator->suspend($txId);
                }
            }

            if ($this->coordinator !== null) {
                $owner = TxApiController::currentOwnerKey($request);
                if ($owner !== null && $this->coordinator->hasOpenTransactionForOwner($owner)) {
                    throw new TransactionConflictException('Write without ' . TxApiController::TX_HEADER
                        . ' while a remote transaction is open for the requester — aborted to preserve atomicity');
                }
            }

            return $this->transactions === null
                ? $endpoint($request)
                : $this->transactions->required(static fn () => $endpoint($request));
        };
    }
}
