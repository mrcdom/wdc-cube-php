<?php

declare(strict_types=1);

namespace Wdc\Tests\Support;

use RuntimeException;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Config\AppConfig;
use Wdc\Framework\Persistence\Database;
use Wdc\Shopping\Persistence\ShoppingRepositories;
use Wdc\Shopping\Persistence\ShoppingSchema;

/**
 * O banco dos testes: um PostgreSQL só para eles, definido em `[test.database]` (url, username,
 * password) no `work/config/application.local.toml`, ou pelas variáveis `WDC_TEST_DB_URL`,
 * `WDC_TEST_DB_USER` e `WDC_TEST_DB_PASSWORD` (que têm precedência — úteis em CI).
 *
 * O schema é recriado uma vez por execução e o seed refeito antes de cada teste. **Nunca aponte
 * para o banco do servidor**: ele é apagado.
 */
final class TestDatabase
{
    private static ?Database $shared = null;

    /** @var array{url: string, username: ?string, password: ?string}|null */
    private static ?array $settings = null;

    /** O banco de testes no seed, com o registro do Beans limpo e os repositórios registrados. */
    public static function open(): Database
    {
        if (self::$shared === null) {
            $s = self::settings();
            self::$shared = Database::open($s['url'], $s['username'], $s['password']);
            ShoppingSchema::drop(self::$shared);
            ShoppingSchema::create(self::$shared);
        } else {
            self::$shared->onQuery(null);
            ShoppingSchema::reset(self::$shared);
        }

        Beans::clear();
        ShoppingRepositories::register(self::$shared);

        return self::$shared;
    }

    /**
     * As chaves de configuração que apontam um servidor de teste para este banco.
     *
     * @return array<string, string>
     */
    public static function serverConfig(): array
    {
        $s = self::settings();

        return array_filter([
            'database.url' => $s['url'],
            'database.username' => $s['username'] ?? '',
            'database.password' => $s['password'] ?? '',
            'database.pool.maxConnections' => '4',
        ], static fn (string $v) => $v !== '');
    }

    /** @return array{url: string, username: ?string, password: ?string} */
    private static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $env = static fn (string $name): ?string => ($v = getenv($name)) === false || $v === '' ? null : $v;
        $config = AppConfig::load('SHOPPING_CONFIG_FILE', dirname(__DIR__, 2) . '/work/config/application.toml');
        $url = $env('WDC_TEST_DB_URL') ?? $config->get('test.database.url');
        if ($url === null || trim($url) === '') {
            throw new RuntimeException(
                'Banco de testes não configurado: defina [test.database] url, username e password em '
                . 'work/config/application.local.toml (ex.: url = "pgsql:host=localhost;port=5432;dbname=wedocode_shopping_php_test")',
            );
        }

        return self::$settings = [
            'url' => $url,
            'username' => $env('WDC_TEST_DB_USER') ?? $config->get('test.database.username'),
            'password' => $env('WDC_TEST_DB_PASSWORD') ?? $config->get('test.database.password'),
        ];
    }
}
