<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\GenericPlace;
use Wdc\Tests\Framework\Cube\Fixture\TestPlace;

final class CubeIntentTest extends TestCase
{
    #[Test]
    public function parse_splitsPlaceAndParameters(): void
    {
        $intent = CubeIntent::parse('product?productId=3&sign=abc');

        self::assertInstanceOf(GenericPlace::class, $intent->place());
        self::assertSame('product', $intent->place()->name());
        self::assertSame(-1, $intent->place()->id());
        self::assertSame(3, $intent->getParameterAsInt('productId'));
        self::assertSame('abc', $intent->getParameterAsString('sign'));
    }

    #[Test]
    public function parse_blank_isUnknown(): void
    {
        self::assertSame('unknown', CubeIntent::parse('  ')->place()?->name());
    }

    #[Test]
    public function toString_isPlaceNameAndQuery_withoutLeadingSlash(): void
    {
        $intent = new CubeIntent();
        $intent->setPlace(TestPlace::PAGE);
        self::assertSame('page', (string) $intent);

        $intent->setParameter('userId', 1);
        $intent->setParameter('productId', 42);
        self::assertSame('page?userId=1&productId=42', (string) $intent, 'insertion order');
    }

    #[Test]
    public function setParameter_null_removes(): void
    {
        $intent = new CubeIntent();
        $intent->setParameter('a', 'x');
        $intent->setParameter('a', null);

        self::assertSame([], $intent->parameters());
    }

    #[Test]
    public function typedGetters_coerceOrFallBack(): void
    {
        $intent = CubeIntent::parse('x?i=42&bad=4x&f=2.5&b=TRUE&multi=7&multi=8');

        self::assertSame(42, $intent->getParameterAsInt('i'));
        self::assertSame(-1, $intent->getParameterAsInt('bad', -1));
        self::assertNull($intent->getParameterAsInt('missing'));
        self::assertSame(0, $intent->getParameterAsInt('missing', 0), 'id 0 is valid: use === null, never !$id');
        self::assertSame(2.5, $intent->getParameterAsFloat('f'));
        self::assertTrue($intent->getParameterAsBool('b'));
        self::assertSame(7, $intent->getParameterAsInt('multi'), 'multi-valued ⇒ first value');
    }

    #[Test]
    public function attributesNeverGoToTheUrl(): void
    {
        $intent = new CubeIntent();
        $intent->setPlace(TestPlace::ROOT);
        $intent->setAttribute('flag', true);
        $slot = new CubeViewSlot(static fn () => null);
        $intent->setViewSlot('owner', $slot);

        self::assertSame('root', (string) $intent);
        self::assertSame($slot, $intent->getViewSlot('owner'));
        self::assertNull($intent->getViewSlot('flag'), 'attribute that is not a slot');
        self::assertTrue($intent->removeAttribute('flag'));
        self::assertNull($intent->getAttribute('flag'));
    }
}
