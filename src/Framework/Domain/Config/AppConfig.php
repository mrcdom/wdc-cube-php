<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Config;

use RuntimeException;

/**
 * External configuration in minimal TOML:
 * `[a.b]` sections, `key = value`, quotes stripped, `#` comments, flattened keys
 * (`section.key`) and values always strings. `application.local.toml` in the same folder
 * overrides the main file.
 *
 * Path resolution: environment variable → default.
 */
final readonly class AppConfig
{
    private const string LOCAL_OVERRIDE_FILE = 'application.local.toml';

    /**
     * @param array<string, string> $properties
     */
    private function __construct(
        private array $properties,
        public string $configFilePath,
    ) {
    }

    public static function load(string $envVar, string $defaultPath): self
    {
        $configured = getenv($envVar);
        $path = is_string($configured) && trim($configured) !== '' ? $configured : $defaultPath;
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException("Configuration file not found: {$path}. Create it or set {$envVar}=<path>");
        }

        $props = self::parseToml(self::read($real));
        $local = dirname($real) . DIRECTORY_SEPARATOR . self::LOCAL_OVERRIDE_FILE;
        if (is_file($local)) {
            $props = array_merge($props, self::parseToml(self::read($local)));   // right-hand side wins
        }

        return new self($props, $real);
    }

    /** @param array<string, string> $properties */
    public static function fromArray(array $properties, string $configFilePath = ''): self
    {
        return new self($properties, $configFilePath);
    }

    public function configFileDir(): string
    {
        return dirname($this->configFilePath);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->properties[$key] ?? $default;
    }

    public function getInt(string $key, int $default): int
    {
        $value = $this->properties[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false ? $default : $int;
    }

    public function getBoolean(string $key, bool $default): bool
    {
        $value = $this->properties[$key] ?? null;

        return $value === null ? $default : strtolower($value) === 'true';
    }

    public function withOverride(string $key, string $value): self
    {
        return new self([...$this->properties, $key => $value], $this->configFilePath);
    }

    /** @return array<string, string> */
    public static function parseToml(string $content): array
    {
        $result = [];
        $section = '';
        foreach (explode("\n", $content) as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                $section = trim(substr($line, 1, -1));
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $result[$section === '' ? $key : "{$section}.{$key}"] = $value;
        }

        return $result;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read config file: {$path}");
        }

        return $content;
    }
}
