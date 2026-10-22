<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest;

use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Closure;
use Psr\Log\LoggerInterface;
use Wdc\Framework\Domain\Exception\TransactionConflictException;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Framework\Persistence\Transaction\RemoteTransactionCoordinator;
use Wdc\Framework\Rest\RestFilter;
use Wdc\Framework\Rest\RestHandler;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Shopping\Persistence\Rest\Security\SecurityEnforcer;
use Wdc\Shopping\Persistence\Rest\Security\SecurityFilter;
use Wdc\Shopping\Persistence\ShoppingRepositories;

/**
 * A API REST de repositório: clientes que não usam o WebSocket (apps nativos, SPAs) acessam
 * os dados por ela.
 *
 * | rota                                         | o quê                                   |
 * |----------------------------------------------|-----------------------------------------|
 * | `/api/auth/{challenge,login,refresh,logout}` | login por desafio HMAC, JWT             |
 * | `/api/repo/<entidade>/<operação>`            | user, product, purchase, purchase-item  |
 * | `/api/repo/product/{id}/image`               | imagem (GET público, PUT protegido)     |
 * | `/api/tx/{begin,commit,rollback,status}`     | transação remota dirigida pelo cliente  |
 *
 * Com AuthenticationService, `/api/repo/*` e `/api/tx/*` exigem um Bearer JWT. Sem ele
 * (segurança desligada), as rotas de auth nem existem e nada é conferido.
 */
final class RepositoryApiRoutes
{
    private readonly SecurityEnforcer $security;

    private readonly ?RestFilter $filter;

    /** @var list<ApiController> */
    private readonly array $controllers;

    public function __construct(
        ShoppingRepositories $repos,
        private readonly ?AuthenticationService $auth,
        private readonly ?RemoteTransactionCoordinator $coordinator,
        private readonly ?TransactionService $transactions,
        private readonly LoggerInterface $logger,
    ) {
        $this->security = new SecurityEnforcer($auth);
        $this->filter = $auth === null ? null : new SecurityFilter($auth);
        $this->controllers = [
            new UserApiController($this->security, $repos->users, $logger),
            new ProductApiController($this->security, $repos->products, $logger),
            new PurchaseApiController($this->security, $repos->purchases),
            new PurchaseItemApiController($this->security, $repos->purchaseItems, $repos->purchases),
        ];
    }

    /**
     * Registra as rotas sob um prefixo: '' (raiz) ou o de um frontend (`/{ctx:...}`), para que
     * uma SPA servida num contexto chame a API sem CORS.
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
     * As rotas protegidas (`/api/repo/*`, `/api/tx/*`), sem servidor HTTP — também para testes.
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
     * Envolve um endpoint de **escrita** numa transação:
     *
     * - com `X-Tx-Id`: junta-se à transação remota do cliente (resume/suspend), sem commit — a
     *   fronteira é do cliente;
     * - sem `X-Tx-Id`, mas com transação remota aberta para o solicitante: o cabeçalho se perdeu
     *   no caminho → 409, em vez de gravar fora da transação (quebraria a atomicidade do bloco);
     * - senão, uma transação por requisição (commit no retorno, rollback na exceção).
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
                    throw new TransactionConflictException('Escrita sem ' . TxApiController::TX_HEADER
                        . ' enquanto há transação remota aberta para o solicitante — abortada para preservar atomicidade');
                }
            }

            return $this->transactions === null
                ? $endpoint($request)
                : $this->transactions->required(static fn () => $endpoint($request));
        };
    }
}
