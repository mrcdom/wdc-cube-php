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
            new DirectKeyDerivation()->derive('senha-da-sessao', $salt),
            new ParallelKeyDerivation()->derive('senha-da-sessao', $salt),
        );
    }

    #[Test]
    public function parallel_doesNotBlockTheEventLoop(): void
    {
        $derivation = new ParallelKeyDerivation();
        $derivation->derive('aquece', 'o-pool');   // sobe o worker antes de medir

        $ticks = 0;
        $ticker = async(static function () use (&$ticks): void {   // outra "sessão": um timer de 10 ms
            for ($i = 0; $i < 100; $i++) {
                delay(0.01);
                $ticks++;
            }
        });
        $derivation->derive('senha', random_bytes(16));   // ~180 ms de CPU, em outro processo
        $ticker->ignore();

        self::assertGreaterThan(5, $ticks, 'o timer seguiu rodando enquanto o PBKDF2 calculava');
    }
}
