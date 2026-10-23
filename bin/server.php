<?php

declare(strict_types=1);

/*
 * Starts the Store server.
 *
 *   php bin/server.php [port]
 *
 * Port: argument > SERVER_PORT variable > server.port from application.toml > 8080.
 * Config: STORE_CONFIG_FILE or work/config/application.toml. Log: LOG_LEVEL (default info).
 */

use Psr\Log\LogLevel;
use Wdc\Framework\Commons\Log\ConsoleLogger;
use Wdc\Store\Domain\StoreConfig;
use Wdc\Store\Server\StoreServer;

use function Amp\trapSignal;

require dirname(__DIR__) . '/vendor/autoload.php';

chdir(dirname(__DIR__));   // relative paths in the config are resolved from the project root

$logger = new ConsoleLogger(getenv('LOG_LEVEL') ?: LogLevel::INFO);
$config = StoreConfig::load();
$port = (int) ($argv[1] ?? getenv('SERVER_PORT') ?: $config->config->getInt('server.port', 8080));

$server = new StoreServer($config, $logger);
$server->start($port);

// the process stays here, with the event loop running, until Ctrl+C (SIGINT) or SIGTERM
$signal = trapSignal([SIGINT, SIGTERM]);
$logger->info("Signal {$signal} received, shutting down");
$server->stop();
