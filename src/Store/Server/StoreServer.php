<?php

declare(strict_types=1);

namespace Wdc\Store\Server;

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
use Wdc\Store\Domain\StoreConfig;
use Wdc\Store\Persistence\Rest\RepositoryApiRoutes;
use Wdc\Store\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Store\Persistence\StoreRepositories;
use Wdc\Store\Persistence\StoreSchema;
use Wdc\Store\Presentation\StoreContext;
use Wdc\Store\RemoteHost\StoreRemoteHost;

/**
 * The Store server, built on Amp:
 *
 * | route                                   | what                                          |
 * |-----------------------------------------|-----------------------------------------------|
 * | `/dispatcher/{appId}` (and `/<shell>/...`) | protocol WebSocket, subprotocol `wdc`      |
 * | `/<shell>/index.html`                    | shell page + `app_id`/`app_skey` cookies      |
 * | `/<shell>/...`                           | files from work/frontend/<shell>              |
 * | `/api/session/init`                      | cookieless session start (native apps)        |
 * | `/image/product/{id}.png`                | product image                                 |
 * | `/web-cache/<host>/<path>`               | CDN fonts and icons, cached on disk           |
 * | `/api/auth/*`, `/api/repo/*`, `/api/tx/*` | REST API + JWT (also under `/<frontend>/`)   |
 * | `/health`, `/`                           | status; redirects to the React shell          |
 * | `POST /__dev/db-reset` (devMode)         | resets the database to the seed               |
 *
 * Everything runs in a single process and a single event loop: sessions, timers and HTTP requests.
 */
final class StoreServer
{
    public const string DEFAULT_SHELL = 'store.shell.react';

    public readonly Database $db;

    public readonly StoreRemoteHost $host;

    private readonly SocketHttpServer $http;

    private readonly Router $router;

    public readonly SqlRemoteTransactionCoordinator $remoteTransactions;

    public function __construct(
        private readonly StoreConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        $scheduler = new EventLoopScheduler($logger);
        $this->db = StoreRepositories::open(
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
        $context = new StoreContext(
            $scheduler,
            $logger,
            simulateSlowLogin: $config->config->getBoolean('simulation.slowLogin', false),
        );
        // the handshake PBKDF2 (~180 ms of CPU) runs in separate processes, off the event loop
        $this->host = StoreRemoteHost::fromConfig($config, $context, new ParallelKeyDerivation());
        $this->host->registry->init($scheduler);

        // a generous per-IP limit: in dev the browser opens many connections from the same address
        $this->http = SocketHttpServer::createForDirectAccess($logger, connectionLimitPerIp: 1000);
        $this->router = $this->buildRouter();
    }

    public function start(int $port, string $address = '0.0.0.0'): void
    {
        $this->http->expose("{$address}:{$port}");
        $this->http->start($this->router, new DefaultErrorHandler());
        $this->logger->info("Store (PHP) at http://localhost:{$port}/" . self::DEFAULT_SHELL . '/index.html');
    }

    /** The port the server actually listens on (useful with port 0 = "any free one"). */
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

        // REST API at the root (native apps) and under each frontend (SPA without CORS)
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
                StoreSchema::reset($this->db);
                $this->logger->warning('Database reset to seed (dev mode)');

                return new Response(HttpStatus::OK, ['content-type' => 'application/json'], '{"reset":true}');
            }));
        }

        if (is_dir($frontend)) {
            $static = new DocumentRoot($this->http, $errorHandler, $frontend);
            $static->setIndexes([]);   // index.html only through the route that sets the cookies
            $static->setFallback(new SpaFallbackHandler($frontend));
            $router->setFallback($static);
        } else {
            $this->logger->warning("Frontends folder not found: {$frontend}");
            $router->setFallback(new SpaFallbackHandler($frontend));
        }

        return $router;
    }
}
