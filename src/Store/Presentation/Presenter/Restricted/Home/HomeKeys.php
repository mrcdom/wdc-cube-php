<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Restricted\Home;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Store\Presentation\Presenter\Restricted\RestrictedKeys;
use Wdc\Store\Presentation\Place;

/** Home keys: `home` - the base of the screens that open inside it. */
class HomeKeys extends RestrictedKeys
{
    public function place(): CubePlace
    {
        return Place::HOME;
    }
}
