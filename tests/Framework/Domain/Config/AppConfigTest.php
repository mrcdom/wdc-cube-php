<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Domain\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Domain\Config\AppConfig;

final class AppConfigTest extends TestCase
{
    #[Test]
    public function parseToml_flattensSectionsAndStripsQuotes(): void
    {
        $toml = <<<'TOML'
            # comentário
            [app]
            basedir = ".."

            [security]
            jwt.secret = 'abc'
            [server]
            port = 8080
            devMode = true
            TOML;

        self::assertSame([
            'app.basedir' => '..',
            'security.jwt.secret' => 'abc',
            'server.port' => '8080',
            'server.devMode' => 'true',
        ], AppConfig::parseToml($toml));
    }

    #[Test]
    public function typedGetters_fallBackToDefault(): void
    {
        $config = AppConfig::fromArray(['server.port' => '8080', 'server.devMode' => 'TRUE', 'bad' => 'x']);

        self::assertSame(8080, $config->getInt('server.port', 1));
        self::assertSame(1, $config->getInt('bad', 1));
        self::assertTrue($config->getBoolean('server.devMode', false));
        self::assertFalse($config->getBoolean('missing', false));
        self::assertSame('d', $config->get('missing', 'd'));
    }

    #[Test]
    public function load_appliesLocalOverride(): void
    {
        $dir = sys_get_temp_dir() . '/wdc-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/application.toml", "[a]\nx = 1\ny = 2\n");
        file_put_contents("{$dir}/application.local.toml", "[a]\ny = 3\n");
        try {
            $config = AppConfig::load('WDC_TEST_UNSET_ENV', "{$dir}/application.toml");
            self::assertSame('1', $config->get('a.x'));
            self::assertSame('3', $config->get('a.y'));
        } finally {
            array_map(unlink(...), glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    }
}
