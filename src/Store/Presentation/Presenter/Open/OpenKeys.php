<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Open;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Store\Presentation\Presenter\RootKeys;
use Wdc\Store\Presentation\Place;

/** Keys of the public area (a guard, no names of its own). */
class OpenKeys extends RootKeys
{
    public function place(): CubePlace
    {
        return Place::OPEN;
    }
}
