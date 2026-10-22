<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeView;

/**
 * Uma aplicação Cube servida remotamente. A infraestrutura toda mora no
 * RemoteApplicationSupport; a app só responde o que é dela.
 *
 * A interface é só o que é da app — quem precisa da
 * infraestrutura chama `remote()`.
 */
interface RemoteApplication
{
    public function remote(): RemoteApplicationSupport;

    public function cubeApp(): CubeApplication;

    public function isAuthenticated(): bool;

    /** A view do presenter raiz — o conteúdo da Browser view. */
    public function rootPresenterView(): ?CubeView;

    /** Navega até o intent (vindo do cliente, já com a assinatura conferida). */
    public function performGo(CubeIntent $intent): void;

    public function release(): void;
}
