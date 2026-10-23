<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\ViewState;

final class BrowserState implements ViewState
{
    public ?string $instanceId = null;

    /** 0 = no alert; -1 = unexpected error (args: message and detail) */
    public int $alertId = 0;

    /** @var list<string> */
    public array $alertArgs = [];

    /**
     * The same alert, in the field the React shell reads. alertId/alertArgs alone are not enough
     * for alerts to show up in React; we send both (null = no alert, omitted).
     */
    public ?AlertMessage $alertMessage = null;

    public ?CubeView $contentView = null;
}
