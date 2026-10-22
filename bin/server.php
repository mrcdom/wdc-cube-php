<?php

declare(strict_types=1);

/*
 * Sobe o servidor do Shopping.
 *
 *   php bin/server.php [porta]
 *
 * Porta: argumento > variável SERVER_PORT > server.port do application.toml > 8080.
 * Config: SHOPPING_CONFIG_FILE ou work/config/application.toml. Log: LOG_LEVEL (padrão info).
 */

use Psr\Log\LogLevel;
use Wdc\Framework\Commons\Log\ConsoleLogger;
use Wdc\Shopping\Domain\ShoppingConfig;
use Wdc\Shopping\Server\ShoppingServer;

use function Amp\trapSignal;

require dirname(__DIR__) . '/vendor/autoload.php';

chdir(dirname(__DIR__));   // caminhos relativos da config são a partir da raiz do projeto

$logger = new ConsoleLogger(getenv('LOG_LEVEL') ?: LogLevel::INFO);
$config = ShoppingConfig::load();
$port = (int) ($argv[1] ?? getenv('SERVER_PORT') ?: $config->config->getInt('server.port', 8080));

$server = new ShoppingServer($config, $logger);
$server->start($port);

// o processo fica aqui, com o event loop rodando, até Ctrl+C (SIGINT) ou SIGTERM
$signal = trapSignal([SIGINT, SIGTERM]);
$logger->info("Sinal {$signal} recebido, encerrando");
$server->stop();
