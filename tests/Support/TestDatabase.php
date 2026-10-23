<?php

declare(strict_types=1);

namespace Wdc\Tests\Support;

use RuntimeException;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Config\AppConfig;
use Wdc\Framework\Persistence\Database;
use Wdc\Store\Persistence\StoreRepositories;
use Wdc\Store\Persistence\StoreSchema;

/**
 * The test database: a PostgreSQL database reserved for tests, defined in `[test.database]` (url, username,
 * password) in `work/config/application.local.toml`, or by the `WDC_TEST_DB_URL`, `WDC_TEST_DB_USER`
 * and `WDC_TEST_DB_PASSWORD` variables (which take precedence — handy in CI).
 *
 * The schema is recreated once per run and the seed is reloaded before each test. **Never point this
 * at the server database**: it gets wiped.
 */
final class TestDatabase
{
    private static ?Database $shared = null;

    /** @var array{url: string, username: ?string, password: ?string}|null */
    private static ?array $settings = null;

    /** The test database at the seed state, with the Beans registry cleared and the repositories registered. */
    public static function open(): Database
    {
        if (self::$shared === null) {
            $s = self::settings();
            self::$shared = Database::open($s['url'], $s['username'], $s['password']);
            StoreSchema::drop(self::$shared);
            StoreSchema::create(self::$shared);
        } else {
            self::$shared->onQuery(null);
            StoreSchema::reset(self::$shared);
        }

        Beans::clear();
        StoreRepositories::register(self::$shared);

        return self::$shared;
    }

    /**
     * The configuration keys that point a test server at this database.
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
        $config = AppConfig::load('STORE_CONFIG_FILE', dirname(__DIR__, 2) . '/work/config/application.toml');
        $url = $env('WDC_TEST_DB_URL') ?? $config->get('test.database.url');
        if ($url === null || trim($url) === '') {
            throw new RuntimeException(
                'Test database not configured: set [test.database] url, username and password in '
                . 'work/config/application.local.toml (e.g. url = "pgsql:host=localhost;port=5432;dbname=wedocode_store_php_test")',
            );
        }

        return self::$settings = [
            'url' => $url,
            'username' => $env('WDC_TEST_DB_USER') ?? $config->get('test.database.username'),
            'password' => $env('WDC_TEST_DB_PASSWORD') ?? $config->get('test.database.password'),
        ];
    }
}
