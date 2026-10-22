<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube\Fixture;

use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubePlace;
use Wdc\Framework\Cube\CubePresenter;

/**
 * Places de teste. Árvore: root → section → page; root → other; root → login.
 * Ids crescem com a profundidade (invariante do motor).
 */
enum TestPlace: int implements CubePlace
{
    case ROOT = 0;
    case SECTION = 1;
    case LOGIN = 2;
    case OTHER = 3;
    case PAGE = 4;

    public function id(): int
    {
        return $this->value;
    }

    public function name(): string
    {
        return strtolower($this->name);
    }

    public function createPresenter(CubeApplication $app): CubePresenter
    {
        assert($app instanceof TestApp);

        return new RecordingPresenter($app, $this);
    }
}
