<?php

declare(strict_types=1);

namespace Wdc\Shopping\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Websocket\Server\Websocket;
use Psr\Log\LoggerInterface;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\EventLoopScheduler;
use Wdc\Framework\Cube\Remote\Http\DispatcherEndpoint;
use Wdc\Framework\Cube\Remote\Http\IndexHtmlHandler;
use Wdc\Framework\Cube\Remote\Http\SessionInitHandler;
use Wdc\Framework\Cube\Remote\Http\SpaFallbackHandler;
use Wdc\Framework\Cube\Remote\Http\WdcWebsocketAcceptor;
use Wdc\Framework\Cube\Remote\Http\WebCacheHandler;
use Wdc\Framework\Cube\Remote\ParallelKeyDerivation;
use Wdc\Framework\Cube\Remote\RemoteDispatcher;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\Transaction\RemoteTransactionOptions;
use Wdc\Framework\Persistence\Transaction\SqlRemoteTransactionCoordinator;
use Wdc\Shopping\Domain\ShoppingConfig;
use Wdc\Shopping\Persistence\Rest\RepositoryApiRoutes;
use Wdc\Shopping\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Shopping\Persistence\ShoppingRepositories;
use Wdc\Shopping\Persistence\ShoppingSchema;
use Wdc\Shopping\Presentation\ShoppingContext;
use Wdc\Shopping\RemoteHost\ShoppingRemoteHost;

/**
 * O servidor do Shopping, montado sobre o Amp:
 *
 * | rota                                    | o quê                                         |
 * |-----------------------------------------|-----------------------------------------------|
 * | `/dispatcher/{appId}` (e `/<shell>/...`) | WebSocket do protocolo, subprotocolo `wdc`    |
 * | `/<shell>/index.html`                    | página do shell + cookies `app_id`/`app_skey` |
 * | `/<shell>/...`                           | arquivos de work/frontend/<shell>             |
 * | `/api/session/init`                      | início de sessão sem cookies (apps nativos)   |
 * | `/image/product/{id}.png`                | imagem do produto                             |
 * | `/web-cache/<host>/<caminho>`            | fontes e ícones da CDN, com cache em disco    |
 * | `/api/auth/*`, `/api/repo/*`, `/api/tx/*` | API REST + JWT (também sob `/<frontend>/`)    |
 * | `/health`, `/`                           | status; redireciona para o shell React        |
 * | `POST /__dev/db-reset` (devMode)         | volta o banco ao seed                         |
 *
 * Tudo roda num único processo e num único event loop: sessões, timers e requests HTTP.
 */
final class ShoppingServer
{
    public const string DEFAULT_SHELL = 'shopping.shell.react';

    public readonly Database $db;

    public readonly ShoppingRemoteHost $host;

    private readonly SocketHttpServer $http;

    private readonly Router $router;

    public readonly SqlRemoteTransactionCoordinator $remoteTransactions;

    public function __construct(
        private readonly ShoppingConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        $scheduler = new EventLoopScheduler($logger);
        $this->db = ShoppingRepositories::open(
            $config->databaseDsn(),
            $config->databaseUser(),
            $config->databasePassword(),
            $config->databaseMaxConnections(),
        );
        $jwtSecret = $config->jwtSecret;
        $auth = $jwtSecret === null || $jwtSecret === ''
            ? null
            : new AuthenticationServiceImpl($jwtSecret, $scheduler, logger: $logger);
        Beans::set(AuthenticationService::class, $auth);
        $this->remoteTransactions = new SqlRemoteTransactionCoordinator($this->db, RemoteTransactionOptions::fromConfig($config->config), logger: $logger);
        $context = new ShoppingContext(
            $scheduler,
            $logger,
            simulateSlowLogin: $config->config->getBoolean('simulation.slowLogin', false),
        );
        // o PBKDF2 do handshake (~180 ms de CPU) roda em processos à parte, fora do event loop
        $this->host = ShoppingRemoteHost::fromConfig($config, $context, new ParallelKeyDerivation());
        $this->host->registry->init($scheduler);

        // um limite por IP generoso: em dev o browser abre muitas conexões do mesmo endereço
        $this->http = SocketHttpServer::createForDirectAccess($logger, connectionLimitPerIp: 1000);
        $this->router = $this->buildRouter();
    }

    public function start(int $port, string $address = '0.0.0.0'): void
    {
        $this->http->expose("{$address}:{$port}");
        $this->http->start($this->router, new DefaultErrorHandler());
        $this->logger->info("Shopping (PHP) em http://localhost:{$port}/" . self::DEFAULT_SHELL . '/index.html');
    }

    /** A porta em que o servidor de fato escuta (útil com porta 0 = "qualquer livre"). */
    public function port(): int
    {
        $address = $this->http->getServers()[0]->getAddress();

        return $address instanceof \Amp\Socket\InternetAddress ? $address->getPort() : 0;
    }

    public function stop(): void
    {
        $this->host->registry->stop();
        $this->http->stop();
        $this->remoteTransactions->close();
        $this->db->close();
    }

    private function buildRouter(): Router
    {
        $errorHandler = new DefaultErrorHandler();
        $frontend = $this->config->baseDir . '/frontend';
        $router = new Router($this->http, $this->logger, $errorHandler);

        $dispatcher = new RemoteDispatcher($this->host->registry, $this->host->security, $this->logger);
        $websocket = new Websocket($this->http, $this->logger, new WdcWebsocketAcceptor(), new DispatcherEndpoint($dispatcher, $this->logger));
        $router->addRoute('GET', '/dispatcher/{id}', $websocket);
        $router->addRoute('GET', '/{ctx:' . IndexHtmlHandler::CONTEXT_PATTERN . '}/dispatcher/{id}', $websocket);

        $index = new IndexHtmlHandler($this->host->security, $frontend);
        $router->addRoute('GET', '/{ctx:' . IndexHtmlHandler::CONTEXT_PATTERN . '}/index.html', $index);
        $router->addRoute('GET', '/{ctx:' . IndexHtmlHandler::CONTEXT_PATTERN . '}/', $index);

        $init = new SessionInitHandler($this->host->security, $this->host->registry);
        $router->addRoute('GET', '/api/session/init', $init);
        $router->addRoute('GET', '/{ctx:' . IndexHtmlHandler::CONTEXT_PATTERN . '}/api/session/init', $init);

        $image = new ProductImageHandler($this->logger);
        $router->addRoute('GET', '/image/product/{id:\d+}.png', $image);
        $router->addRoute('GET', '/{ctx:[^/]+}/image/product/{id:\d+}.png', $image);

        // API REST na raiz (apps nativos) e sob cada frontend (SPA sem CORS)
        $api = new RepositoryApiRoutes($this->remoteTransactions, $this->logger);
        $api->configure($router);
        foreach (is_dir($frontend) ? (scandir($frontend) ?: []) : [] as $name) {
            if ($name[0] !== '.' && is_dir("{$frontend}/{$name}")) {
                $api->configure($router, '/' . $name);
            }
        }

        $router->addRoute('GET', '/web-cache/{path:.+}', new WebCacheHandler($this->config->dataDir . '/web-cache', $this->logger));

        $router->addRoute('GET', '/health', new ClosureRequestHandler(
            static fn () => new Response(HttpStatus::OK, ['content-type' => 'application/json'], '{"status":"UP"}'),
        ));
        $router->addRoute('GET', '/', new ClosureRequestHandler(
            static fn () => new Response(HttpStatus::FOUND, ['location' => '/' . self::DEFAULT_SHELL . '/index.html']),
        ));
        if ($this->config->config->getBoolean('server.devMode', false)) {
            $router->addRoute('POST', '/__dev/db-reset', new ClosureRequestHandler(function () {
                ShoppingSchema::reset($this->db);
                $this->logger->warning('Database reset to seed (dev mode)');

                return new Response(HttpStatus::OK, ['content-type' => 'application/json'], '{"reset":true}');
            }));
        }

        if (is_dir($frontend)) {
            $static = new DocumentRoot($this->http, $errorHandler, $frontend);
            $static->setIndexes([]);   // index.html só pela rota que planta os cookies
            $static->setFallback(new SpaFallbackHandler($frontend));
            $router->setFallback($static);
        } else {
            $this->logger->warning("Pasta de frontends não encontrada: {$frontend}");
            $router->setFallback(new SpaFallbackHandler($frontend));
        }

        return $router;
    }
}
