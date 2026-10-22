<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain;

use RuntimeException;
use Wdc\Framework\Domain\Config\AppConfig;

/**
 * Diretórios de runtime e segredos da aplicação.
 *
 * Um objeto imutável criado uma vez no composition root e passado adiante.
 */
final readonly class ShoppingConfig
{
    public const string CONFIG_FILE_ENV = 'SHOPPING_CONFIG_FILE';
    public const string DEFAULT_CONFIG_PATH = 'work/config/application.toml';

    private function __construct(
        public AppConfig $config,
        public string $baseDir,
        public string $configDir,
        public string $dataDir,
        public string $logDir,
        public string $tempDir,
        public ?string $jwtSecret,
    ) {
    }

    public static function load(): self
    {
        return self::configure(AppConfig::load(self::CONFIG_FILE_ENV, self::DEFAULT_CONFIG_PATH));
    }

    /** `app.basedir` é relativo à pasta do arquivo de configuração; cria config/ data/ log/ temp/. */
    public static function configure(AppConfig $config): self
    {
        $configured = $config->get('app.basedir');
        if ($configured === null || trim($configured) === '') {
            throw new RuntimeException("Property 'app.basedir' is required in {$config->configFilePath}");
        }
        $baseDir = self::mkdir(self::isAbsolute($configured) ? $configured : $config->configFileDir() . '/' . $configured);

        return new self(
            $config,
            $baseDir,
            self::mkdir($baseDir . '/config'),
            self::mkdir($baseDir . '/data'),
            self::mkdir($baseDir . '/log'),
            self::mkdir($baseDir . '/temp'),
            $config->get('security.jwt.secret'),
        );
    }

    public const string DEFAULT_DATABASE_URL = 'pgsql:host=localhost;port=5432;dbname=wedocode_shopping_php';

    /** Endereço do PostgreSQL: `database.url` (ex.: `pgsql:host=localhost;port=5432;dbname=...`). */
    public function databaseDsn(): string
    {
        $url = $this->config->get('database.url');

        return $url !== null && trim($url) !== '' ? $url : self::DEFAULT_DATABASE_URL;
    }

    /**
     * Tamanho do pool de conexões (`database.pool.maxConnections`).
     *
     * @return positive-int
     */
    public function databaseMaxConnections(): int
    {
        return max(1, $this->config->getInt('database.pool.maxConnections', 16));
    }

    public function databaseUser(): ?string
    {
        return $this->config->get('database.username');
    }

    public function databasePassword(): ?string
    {
        return $this->config->get('database.password');
    }

    private static function mkdir(string $dir): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
        $real = realpath($dir);

        return $real === false ? $dir : $real;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }
}
