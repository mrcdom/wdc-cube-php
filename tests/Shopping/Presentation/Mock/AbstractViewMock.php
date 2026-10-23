<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation\Mock;

use PHPUnit\Framework\Assert;
use Wdc\Framework\Cube\CubeView;

/**
 * Test view: draws nothing; holds the presenter and the SAME state object it
 * changes (PHP objects are shared by handle, not copied).
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

    /** Checks the view type with a helpful message and returns it already typed. */
    public static function cast(?CubeView $view): static
    {
        if (!$view instanceof static) {
            Assert::fail(sprintf('Expected %s, got %s', static::class, $view === null ? 'null' : $view::class));
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
