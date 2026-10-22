#!/usr/bin/env bash
# Roda a suíte de testes no PostgreSQL, com o banco definido em [test.database] do
# work/config/application.local.toml (url, username, password).
set -euo pipefail
cd "$(dirname "$0")/.."
eval "$(php -r '
require "vendor/autoload.php";
$c = Wdc\Framework\Domain\Config\AppConfig::load("SHOPPING_CONFIG_FILE", "work/config/application.toml");
foreach (["URL" => "url", "USER" => "username", "PASSWORD" => "password"] as $env => $key) {
    echo "export WDC_TEST_DB_$env=" . escapeshellarg((string) $c->get("test.database.$key")) . "\n";
}')"
[ -n "$WDC_TEST_DB_URL" ] || { echo "Defina [test.database] url em work/config/application.local.toml" >&2; exit 1; }
exec vendor/bin/phpunit "$@"
