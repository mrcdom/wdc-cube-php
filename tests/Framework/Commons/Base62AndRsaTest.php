<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Commons;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Codec\Base62;
use Wdc\Framework\Commons\Security\Rsa;

final class Base62AndRsaTest extends TestCase
{
    #[Test]
    public function base62_usesTheGmpAlphabet(): void
    {
        self::assertSame('z', Base62::encode("\x3D"));          // 61
        self::assertSame('10', Base62::encode("\x3E"));         // 62
        self::assertSame(gmp_strval(gmp_import('olá mundo'), 62), Base62::encode('olá mundo'));
    }

    #[Test]
    public function base62_keepsLeadingZeroBytes(): void
    {
        self::assertSame('01', Base62::encode("\x00\x01"), 'o GMP daria só "1"');
        self::assertSame('001', Base62::encode("\x00\x00\x01"));
        self::assertSame('0', Base62::encode("\x00"), 'o último zero não ganha dígito extra');
    }

    #[Test]
    public function base62_roundTrips(): void
    {
        foreach (["\x00\x00\xFF", random_bytes(16), random_bytes(32), 'texto'] as $bytes) {
            self::assertSame($bytes, Base62::decode(Base62::encode($bytes)));
        }
    }

    #[Test]
    public function rsa_textbook_roundTripsAndSerializesInBase36(): void
    {
        $rsa = Rsa::generate(512);
        $message = gmp_import('segredo da sessão');

        self::assertEquals($message, $rsa->decrypt($rsa->encrypt($message)));
        self::assertGreaterThanOrEqual(500, strlen(gmp_strval($rsa->modulus, 2)));

        $copy = Rsa::fromBase36($rsa->publicKeyBase36(), $rsa->privateKeyBase36());
        self::assertEquals($message, $copy->decrypt($rsa->encrypt($message)));
        self::assertStringStartsWith('1ekh:', $rsa->publicKeyBase36(), '65537 em base 36');
    }
}
