<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeView;

/**
 * A Cube application served remotely. All the infrastructure lives in
 * RemoteApplicationSupport; the app only answers for what belongs to it.
 *
 * The interface holds only what belongs to the app — whoever needs the
 * infrastructure calls `remote()`.
 */
interface RemoteApplication
{
    public function remote(): RemoteApplicationSupport;

    public function cubeApp(): CubeApplication;

    public function isAuthenticated(): bool;

    /** The root presenter's view — the content of the Browser view. */
    public function rootPresenterView(): ?CubeView;

    /** Navigates to the intent (coming from the client, with its signature already checked). */
    public function performGo(CubeIntent $intent): void;

    public function release(): void;
}
