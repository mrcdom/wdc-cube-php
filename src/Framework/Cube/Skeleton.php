<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use Closure;
use LogicException;

/**
 * CubeSkeleton montado com closures.
 *
 * Em PHP uma classe anônima não enxerga os privados de quem a criou; uma closure criada
 * dentro do presenter enxerga. Então o presenter escreve:
 *
 *     return new Skeleton('473dbdd7a36a', function (int $event, int $qtde, array $form): void {
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
     * Evento que o presenter não conhece: lança o erro para o evento não sumir em silêncio.
     */
    public static function unknownEvent(int $eventCode): never   // `never`: a função nunca retorna
    {
        throw new LogicException("eventCode({$eventCode}) not handled");
    }

    public function classId(): string
    {
        return $this->classId;
    }

    public function submit(int $eventCode, int $eventQtde, array $formData): void
    {
        ($this->submit)($eventCode, $eventQtde, $formData);
    }

    public function syncState(array $formData): void
    {
        if ($this->syncState !== null) {
            ($this->syncState)($formData);
        }
    }
}
