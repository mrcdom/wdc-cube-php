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
            // `function () use (&$log)`, not `fn`: an arrow function would copy $log
            async(function () use ($lock, &$log): void {
                $lock->run(function () use (&$log): void {
                    $log[] = 'A enters';
                    delay(0.05);   // suspends while holding the lock
                    $log[] = 'A leaves';
                });
            }),
            async(function () use ($lock, &$log): void {
                $lock->run(function () use (&$log): void {
                    $log[] = 'B enters';
                });
            }),
        ]);

        self::assertSame(['A enters', 'A leaves', 'B enters'], $log);
    }

    #[Test]
    public function theOwner_canTakeItAgain(): void
    {
        $lock = new ReentrantLock();

        $result = $lock->run(fn () => $lock->run(fn () => $lock->isHeldByCurrentFiber() ? 'reentered' : 'no'));

        self::assertSame('reentered', $result);
        self::assertFalse($lock->isHeldByCurrentFiber());
    }
}
