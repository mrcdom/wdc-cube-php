<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Commons;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Concurrent\ReentrantLock;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class ReentrantLockTest extends TestCase
{
    #[Test]
    public function otherFibers_waitWhileTheOwnerIsSuspended(): void
    {
        $lock = new ReentrantLock();
        $log = [];

        await([
            // `function () use (&$log)`, não `fn`: a arrow function copiaria $log
            async(function () use ($lock, &$log): void {
                $lock->run(function () use (&$log): void {
                    $log[] = 'A entra';
                    delay(0.05);   // suspende segurando o lock
                    $log[] = 'A sai';
                });
            }),
            async(function () use ($lock, &$log): void {
                $lock->run(function () use (&$log): void {
                    $log[] = 'B entra';
                });
            }),
        ]);

        self::assertSame(['A entra', 'A sai', 'B entra'], $log);
    }

    #[Test]
    public function theOwner_canTakeItAgain(): void
    {
        $lock = new ReentrantLock();

        $result = $lock->run(fn () => $lock->run(fn () => $lock->isHeldByCurrentFiber() ? 'reentrou' : 'não'));

        self::assertSame('reentrou', $result);
        self::assertFalse($lock->isHeldByCurrentFiber());
    }
}
