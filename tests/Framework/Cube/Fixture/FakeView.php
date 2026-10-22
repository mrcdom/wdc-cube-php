<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube\Fixture;

use Wdc\Framework\Cube\CubeView;

final class FakeView implements CubeView
{
    public int $updates = 0;

    public bool $released = false;

    public function __construct(private readonly string $id)
    {
    }

    public function instanceId(): string
    {
        return $this->id;
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
