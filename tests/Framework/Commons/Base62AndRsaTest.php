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
        self::assertSame(gmp_strval(gmp_import('héllo world'), 62), Base62::encode('héllo world'));
    }

    #[Test]
    public function base62_keepsLeadingZeroBytes(): void
    {
        self::assertSame('01', Base62::encode("\x00\x01"), 'GMP would give just "1"');
        self::assertSame('001', Base62::encode("\x00\x00\x01"));
        self::assertSame('0', Base62::encode("\x00"), 'the last zero gets no extra digit');
    }

    #[Test]
    public function base62_roundTrips(): void
    {
        foreach (["\x00\x00\xFF", random_bytes(16), random_bytes(32), 'text'] as $bytes) {
            self::assertSame($bytes, Base62::decode(Base62::encode($bytes)));
        }
    }

    #[Test]
    public function rsa_textbook_roundTripsAndSerializesInBase36(): void
    {
        $rsa = Rsa::generate(512);
        $message = gmp_import('session secret');

        self::assertEquals($message, $rsa->decrypt($rsa->encrypt($message)));
        self::assertGreaterThanOrEqual(500, strlen(gmp_strval($rsa->modulus, 2)));

        $copy = Rsa::fromBase36($rsa->publicKeyBase36(), $rsa->privateKeyBase36());
        self::assertEquals($message, $copy->decrypt($rsa->encrypt($message)));
        self::assertStringStartsWith('1ekh:', $rsa->publicKeyBase36(), '65537 in base 36');
    }
}
