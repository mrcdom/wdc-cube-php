<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Store\Presentation\Presenter\RootKeys;
use Wdc\Store\Presentation\Place;

/** Keys of the restricted area (a guard, no names of its own). */
class RestrictedKeys extends RootKeys
{
    public function place(): CubePlace
    {
        return Place::RESTRICTED;
    }
}
