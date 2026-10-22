<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Presenter\RootKeys;
use Wdc\Shopping\Presentation\Place;

/** Keys da área restrita (guarda, sem nomes próprios). */
class RestrictedKeys extends RootKeys
{
    public function place(): CubePlace
    {
        return Place::RESTRICTED;
    }
}
