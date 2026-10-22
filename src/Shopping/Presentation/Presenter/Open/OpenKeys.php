<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Presenter\RootKeys;
use Wdc\Shopping\Presentation\Place;

/** Keys da área pública (guarda, sem nomes próprios). */
class OpenKeys extends RootKeys
{
    public function place(): CubePlace
    {
        return Place::OPEN;
    }
}
