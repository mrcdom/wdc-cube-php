<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use Closure;
use LogicException;

/**
 * A CubeSkeleton built from closures.
 *
 * In PHP an anonymous class cannot see the private members of whoever created it; a closure
 * created inside the presenter can. So the presenter writes:
 *
 *     return new Skeleton('473dbdd7a36a', function (int $event, int $count, array $form): void {
 *         match ($event) {
 *             1 => $this->onExit(),
 *             default => Skeleton::unknownEvent($event),
 *         };
 *     });
 */
final readonly class Skeleton implements CubeSkeleton
{
    /**
     * @param Closure(int, int, array<string, mixed>): void      $submit
     * @param (Closure(array<string, mixed>): void)|null        $syncState
     */
    public function __construct(
        private string $classId,
        private Closure $submit,
        private ?Closure $syncState = null,
    ) {
    }

    /**
     * An event the presenter does not know: throws so the event does not vanish silently.
     */
    public static function unknownEvent(int $eventCode): never   // `never`: the function never returns
    {
        throw new LogicException("eventCode({$eventCode}) not handled");
    }

    public function classId(): string
    {
        return $this->classId;
    }

    public function submit(int $eventCode, int $eventCount, array $formData): void
    {
        ($this->submit)($eventCode, $eventCount, $formData);
    }

    public function syncState(array $formData): void
    {
        if ($this->syncState !== null) {
            ($this->syncState)($formData);
        }
    }
}
