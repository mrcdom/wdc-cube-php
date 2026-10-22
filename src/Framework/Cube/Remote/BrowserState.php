<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\ViewState;

final class BrowserState implements ViewState
{
    public ?string $instanceId = null;

    /** 0 = sem alerta; -1 = erro inesperado (args: mensagem e detalhe) */
    public int $alertId = 0;

    /** @var list<string> */
    public array $alertArgs = [];

    /**
     * O mesmo alerta, no campo que o shell React lê. Só alertId/alertArgs não bastam
     * para os alertas aparecerem no React; mandamos os dois (null = sem alerta, omitido).
     */
    public ?AlertMessage $alertMessage = null;

    public ?CubeView $contentView = null;
}
