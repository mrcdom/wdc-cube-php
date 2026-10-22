<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Presenter\Restricted\RestrictedKeys;
use Wdc\Shopping\Presentation\Place;

/** Keys da home: `home` - a base das telas que abrem dentro dela. */
class HomeKeys extends RestrictedKeys
{
    public function place(): CubePlace
    {
        return Place::HOME;
    }
}
