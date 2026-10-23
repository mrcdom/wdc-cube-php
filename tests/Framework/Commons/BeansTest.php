<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Commons;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Beans;

final class BeansTest extends TestCase
{
    protected function tearDown(): void
    {
        Beans::clear();
    }

    #[Test]
    public function of_returnsTheRegisteredImplementation(): void
    {
        $bean = new ArrayIterator([1, 2]);
        Beans::set(Countable::class, $bean);

        self::assertSame($bean, Beans::of(Countable::class));
        self::assertSame(2, Beans::of(Countable::class)->count(), 'PHPStan knows it is a Countable');
    }

    #[Test]
    public function find_returnsNullWhenNothingIsRegistered(): void
    {
        self::assertNull(Beans::find(Countable::class));
    }

    #[Test]
    public function of_withoutRegistration_isAWiringError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No implementation registered for Countable');
        Beans::of(Countable::class);
    }

    #[Test]
    public function setNull_removesTheRegistration(): void
    {
        Beans::set(Countable::class, new ArrayIterator([]));
        Beans::set(Countable::class, null);

        self::assertNull(Beans::find(Countable::class));
    }

    #[Test]
    public function set_refusesAnObjectThatDoesNotImplementTheInterface(): void
    {
        $this->expectException(LogicException::class);
        Beans::set(IteratorAggregate::class, new ArrayIterator([]));
    }
}
