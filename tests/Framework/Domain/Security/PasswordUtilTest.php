<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Domain\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Domain\Security\PasswordUtil;

final class PasswordUtilTest extends TestCase
{
    /**
     * Hashes gravados no seed do banco.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function seedUsers(): iterable
    {
        yield 'admin' => ['admin', '1ymiigxvce4vzea4zp5bsfbgj'];
        yield 'fulano' => ['fulano', '318zxmmy2df32oxnkh6k4km88'];
        yield 'beotrano (negativo)' => ['beotrano', '-17msdx5ah76k0tdyvaoieqemg'];
    }

    #[DataProvider('seedUsers')]
    public function testHashMatchesSeed(string $password, string $expected): void
    {
        self::assertSame($expected, PasswordUtil::hashPassword($password));
    }
}
