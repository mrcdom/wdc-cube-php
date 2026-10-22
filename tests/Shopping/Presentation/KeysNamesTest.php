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
 * Todo nome de parâmetro e de atributo é único na aplicação: uma navegação passa o MESMO intent
 * por todos os `applyParameters` do caminho, e dois donos do mesmo nome se sobrescreveriam em
 * silêncio. Por isso cada `*Keys.php` declara os seus nomes numa tabela local de constantes
 * (`P_*` parâmetros, `A_*` atributos), todos começando por um PREFIX de duas letras só dele.
 * Este teste impede que a regra dependa da memória de alguém.
 */
final class KeysNamesTest extends TestCase
{
    /** Código da aplicação (o framework não declara nomes). */
    private const string SOURCES = __DIR__ . '/../../../src/Shopping';

    /** Acesso ao intent por nome. */
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
                self::assertMatchesRegularExpression("/^self::PREFIX \\. '-[a-z0-9-]+'$/", $value, "{$file}: {$name} fora do formato self::PREFIX . '-código'");
                $codes[] = $value;
            }
            if ($codes !== []) {
                self::assertMatchesRegularExpression('/^[a-z]{2}$/', $prefix[1] ?? '', "{$file}: quem declara nomes precisa de um PREFIX de duas letras");
            }
            self::assertSame(count($codes), count(array_unique($codes)), "{$file}: código local repetido");

            preg_match_all(self::ACCESS, $source, $calls);
            foreach ($calls[1] as $argument) {
                self::assertMatchesRegularExpression('/^self::[PA]_\w+$/', trim($argument), "{$file}: nome solto, fora da tabela");
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

        self::assertSame([], $shared, 'prefixo usado por mais de um arquivo de keys');
    }

    /** Cada place tem a SUA classe de keys - inclusive guardas e telas sem parâmetros. */
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
            $keys = $reflection->newInstanceWithoutConstructor();   // place() não depende do estado
            $owners[$keys->place()->name()][] = $class;
        }

        foreach (Place::cases() as $place) {
            self::assertCount(1, $owners[$place->name()] ?? [], "place {$place->name}: precisa de exatamente uma classe de keys");
        }
    }

    /** @return class-string */
    private static function className(string $path): string
    {
        preg_match('/^namespace ([^;]+);/m', (string) file_get_contents($path), $ns);
        $class = ($ns[1] ?? '') . '\\' . basename($path, '.php');
        self::assertTrue(class_exists($class), "classe {$class} não encontrada");

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

        self::assertSame([], $loose, 'acesso ao intent por nome fora das keys: use a classe de keys do place');
    }
}
