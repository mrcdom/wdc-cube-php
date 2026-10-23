<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Wdc\Framework\Cube\CubeKeys;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * Every parameter and attribute name is unique in the application: a navigation passes the SAME intent
 * through every `applyParameters` along the path, and two owners of the same name would silently
 * overwrite each other. So each `*Keys.php` declares its names in a local table of constants
 * (`P_*` parameters, `A_*` attributes), all starting with a two-letter PREFIX of its own.
 * This test keeps the rule from depending on anyone's memory.
 */
final class KeysNamesTest extends TestCase
{
    /** Application code (the framework declares no names). */
    private const string SOURCES = __DIR__ . '/../../../src/Shopping';

    /** Access to the intent by name. */
    private const string ACCESS = '/\$(?:this->)?intent->(?:getParameter\w*|setParameter|removeParameter|getAttribute|setAttribute|removeAttribute|getViewSlot|setViewSlot)\(\s*([^,)]+)/';

    /** @return list<string> */
    private static function sources(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCES)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function keysFiles(): array
    {
        return array_values(array_filter(self::sources(), static fn (string $f) => str_ends_with($f, 'Keys.php')));
    }

    private static function relative(string $path): string
    {
        return substr($path, strlen((string) realpath(self::SOURCES)) + 1);
    }

    #[Test]
    public function findsTheKeysFiles(): void
    {
        self::assertNotEmpty(self::keysFiles());
    }

    #[Test]
    public function everyNameComesFromTheFilesOwnTable(): void
    {
        foreach (self::keysFiles() as $path) {
            $source = (string) file_get_contents($path);
            $file = self::relative((string) realpath($path));
            preg_match("/private const string PREFIX = '([^']*)';/", $source, $prefix);

            preg_match_all('/const string ([PA]_\w+) = (.+?);/', $source, $tables, PREG_SET_ORDER);
            $codes = [];
            foreach ($tables as [, $name, $value]) {
                self::assertMatchesRegularExpression("/^self::PREFIX \\. '-[a-z0-9-]+'$/", $value, "{$file}: {$name} outside the format self::PREFIX . '-code'");
                $codes[] = $value;
            }
            if ($codes !== []) {
                self::assertMatchesRegularExpression('/^[a-z]{2}$/', $prefix[1] ?? '', "{$file}: a file that declares names needs a two-letter PREFIX");
            }
            self::assertSame(count($codes), count(array_unique($codes)), "{$file}: duplicated local code");

            preg_match_all(self::ACCESS, $source, $calls);
            foreach ($calls[1] as $argument) {
                self::assertMatchesRegularExpression('/^self::[PA]_\w+$/', trim($argument), "{$file}: loose name, outside the table");
            }
        }
    }

    #[Test]
    public function everyKeysFileHasAPrefixOfItsOwn(): void
    {
        $owners = [];
        foreach (self::keysFiles() as $path) {
            if (preg_match("/private const string PREFIX = '([^']*)';/", (string) file_get_contents($path), $m) === 1) {
                $owners[$m[1]][] = self::relative((string) realpath($path));
            }
        }
        $shared = array_filter($owners, static fn (array $files) => count($files) > 1);

        self::assertSame([], $shared, 'prefix used by more than one keys file');
    }

    /** Every place has ITS OWN keys class - guards and parameterless screens included. */
    #[Test]
    public function everyPlaceHasExactlyOneKeysClass(): void
    {
        $owners = [];
        foreach (self::keysFiles() as $path) {
            $class = self::className($path);
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(CubeKeys::class)) {
                continue;
            }
            /** @var CubeKeys<ShoppingApplication> $keys */
            $keys = $reflection->newInstanceWithoutConstructor();   // place() does not depend on state
            $owners[$keys->place()->name()][] = $class;
        }

        foreach (Place::cases() as $place) {
            self::assertCount(1, $owners[$place->name()] ?? [], "place {$place->name}: needs exactly one keys class");
        }
    }

    /** @return class-string */
    private static function className(string $path): string
    {
        preg_match('/^namespace ([^;]+);/m', (string) file_get_contents($path), $ns);
        $class = ($ns[1] ?? '') . '\\' . basename($path, '.php');
        self::assertTrue(class_exists($class), "class {$class} not found");

        return $class;
    }

    #[Test]
    public function outsideTheKeys_nobodyNamesAParameterOrAttribute(): void
    {
        $loose = [];
        foreach (self::sources() as $path) {
            if (str_ends_with($path, 'Keys.php')) {
                continue;
            }
            if (preg_match_all(self::ACCESS, (string) file_get_contents($path), $calls) > 0) {
                $loose[self::relative((string) realpath($path))] = $calls[0];
            }
        }

        self::assertSame([], $loose, 'intent access by name outside the keys: use the place keys class');
    }
}
