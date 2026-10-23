<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Domain\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Domain\Security\PasswordUtil;

final class PasswordUtilTest extends TestCase
{
    /**
     * Hashes stored in the database seed.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function seedUsers(): iterable
    {
        yield 'admin' => ['admin', '1ymiigxvce4vzea4zp5bsfbgj'];
        yield 'john' => ['john', '4vsoa1b14a1q1gjxpuovrmnid'];
        yield 'jane' => ['jane', '584hj4ft2527slv7czmmjdoxn'];
        yield 'shopping (negative)' => ['shopping', '-4k4g46ozrhcc0ed8pgz69wgxx'];
    }

    #[DataProvider('seedUsers')]
    public function testHashMatchesSeed(string $password, string $expected): void
    {
        self::assertSame($expected, PasswordUtil::hashPassword($password));
    }
}
