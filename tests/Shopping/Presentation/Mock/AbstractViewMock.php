<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use PHPUnit\Framework\Assert;
use Wdc\Framework\Cube\CubeView;

/**
 * View de teste: não desenha nada; guarda o presenter e o MESMO objeto de estado que ele
 * altera (objetos PHP são compartilhados por handle, não copiados).
 */
abstract class AbstractViewMock implements CubeView
{
    private static int $instanceIdGen = 0;

    public readonly string $instanceId;

    public bool $released = false;

    public int $updates = 0;

    public function __construct()
    {
        $this->instanceId = (string) ++self::$instanceIdGen;
    }

    /** Confere o tipo da view com uma mensagem útil e a devolve já tipada. */
    public static function cast(?CubeView $view): static
    {
        if (!$view instanceof static) {
            Assert::fail(sprintf('Esperava %s, veio %s', static::class, $view === null ? 'null' : $view::class));
        }

        return $view;
    }

    public function instanceId(): string
    {
        return $this->instanceId;
    }

    public function update(): void
    {
        $this->updates++;
    }

    public function release(): void
    {
        $this->released = true;
    }
}
