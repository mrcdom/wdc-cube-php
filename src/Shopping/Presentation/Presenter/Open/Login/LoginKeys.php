<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Presenter\Open\OpenKeys;
use Wdc\Shopping\Presentation\Place;

/** Keys do login: `login`. */
final class LoginKeys extends OpenKeys
{
    public function place(): CubePlace
    {
        return Place::LOGIN;
    }
}
