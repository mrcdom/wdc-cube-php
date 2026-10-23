<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube\Remote;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Cube\Remote\DirectKeyDerivation;
use Wdc\Framework\Cube\Remote\ParallelKeyDerivation;

use function Amp\async;
use function Amp\delay;

final class KeyDerivationTest extends TestCase
{
    #[Test]
    public function parallel_derivesTheSameKeyAsDirect(): void
    {
        $salt = random_bytes(16);

        self::assertSame(
            new DirectKeyDerivation()->derive('session-password', $salt),
            new ParallelKeyDerivation()->derive('session-password', $salt),
        );
    }

    #[Test]
    public function parallel_doesNotBlockTheEventLoop(): void
    {
        $derivation = new ParallelKeyDerivation();
        $derivation->derive('warm-up', 'the-pool');   // start the worker before measuring

        $ticks = 0;
        $ticker = async(static function () use (&$ticks): void {   // another "session": a 10 ms timer
            for ($i = 0; $i < 100; $i++) {
                delay(0.01);
                $ticks++;
            }
        });
        $derivation->derive('password', random_bytes(16));   // ~180 ms of CPU, in another process
        $ticker->ignore();

        self::assertGreaterThan(5, $ticks, 'the timer kept running while PBKDF2 was computing');
    }
}
