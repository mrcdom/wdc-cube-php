<?php

declare(strict_types=1);

namespace Wdc\Tests\Support;

use Wdc\Framework\Persistence\Database;
use Wdc\Shopping\Persistence\ShoppingRepositories;
use Wdc\Shopping\Persistence\ShoppingSchema;

/**
 * O banco dos testes. Padrão: SQLite em memória, novo a cada teste. Com `WDC_TEST_DB_URL`
 * (DSN do PDO, ex.: `pgsql:host=localhost;dbname=wedocode_shopping_php_test`) e, se preciso,
 * `WDC_TEST_DB_USER`/`WDC_TEST_DB_PASSWORD`, os mesmos testes rodam nesse banco: o schema é
 * recriado uma vez por execução e o seed refeito antes de cada teste. Ver bin/test-pgsql.sh.
 */
final class TestDatabase
{
    private static ?Database $shared = null;

    public static function repositories(): ShoppingRepositories
    {
        $url = getenv('WDC_TEST_DB_URL');
        if ($url === false || $url === '') {
            return ShoppingRepositories::openSqlite(':memory:');
        }
        if (self::$shared === null) {
            self::$shared = Database::open($url, getenv('WDC_TEST_DB_USER') ?: null, getenv('WDC_TEST_DB_PASSWORD') ?: null);
            ShoppingSchema::drop(self::$shared);
            ShoppingSchema::create(self::$shared);
        } else {
            self::$shared->onQuery(null);
            ShoppingSchema::reset(self::$shared);
        }

        return new ShoppingRepositories(self::$shared);
    }

    /**
     * Como repositories(), mas num banco que aceita uma segunda conexão (a transação remota
     * abre uma só dela): no SQLite, um arquivo temporário em vez da memória.
     *
     * @return array{ShoppingRepositories, ?string} os repositórios e o arquivo a apagar no fim
     */
    public static function repositoriesWithConnections(): array
    {
        if (self::dialect() !== 'sqlite') {
            return [self::repositories(), null];
        }
        $file = sys_get_temp_dir() . '/wdc-test-' . bin2hex(random_bytes(4)) . '.db';

        return [ShoppingRepositories::openSqlite($file), $file];
    }

    public static function dialect(): string
    {
        $url = getenv('WDC_TEST_DB_URL');

        return $url === false || $url === '' ? 'sqlite' : explode(':', $url)[0];
    }
}
