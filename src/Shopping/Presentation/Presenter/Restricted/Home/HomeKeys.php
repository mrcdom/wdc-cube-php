<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Home;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Presenter\Restricted\RestrictedKeys;
use Wdc\Shopping\Presentation\Place;

/** Home keys: `home` - the base of the screens that open inside it. */
class HomeKeys extends RestrictedKeys
{
    public function place(): CubePlace
    {
        return Place::HOME;
    }
}
