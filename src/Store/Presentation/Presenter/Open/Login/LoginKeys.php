<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter\Open\Login;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Store\Presentation\Presenter\Open\OpenKeys;
use Wdc\Store\Presentation\Place;

/** Login keys: `login`. */
final class LoginKeys extends OpenKeys
{
    public function place(): CubePlace
    {
        return Place::LOGIN;
    }
}
