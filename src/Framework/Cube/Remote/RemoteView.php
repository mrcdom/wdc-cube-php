<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\PresenterBase;
use Wdc\Framework\Cube\ViewState;

/**
 * A view genérica do modo remoto (RemoteViewImpl): não desenha nada — guarda o ViewState que
 * será serializado e repassa os eventos ao skeleton. Uma única classe serve a todas as telas;
 * o shell React escolhe o componente pelo prefixo do id (`<classId>:<n>`).
 */
final class RemoteView implements CubeView
{
    public readonly string $instanceId;

    public function __construct(
        private readonly RemoteApplication $app,
        public readonly PresenterBase $presenter,
        public readonly ViewState $state,
        private readonly CubeSkeleton $skeleton,
        ?string $instanceId = null,
    ) {
        $this->instanceId = $instanceId ?? $skeleton->classId() . ':' . $app->remote()->nextInstanceId();
        $app->remote()->putView($this);
        $app->remote()->markDirty($this);   // toda view nasce "suja": precisa ir ao cliente
    }

    public function instanceId(): string
    {
        return $this->instanceId;
    }

    public function update(): void
    {
        $this->app->remote()->markDirty($this);
    }

    public function release(): void
    {
        $this->app->remote()->removeView($this->instanceId);
    }

    /** @param array<string, mixed> $formData */
    public function syncClientToServer(array $formData): void
    {
        $this->skeleton->syncState($formData);
    }

    /** @param array<string, mixed> $formData */
    public function submit(int $eventCode, int $eventQtde, array $formData): void
    {
        $this->skeleton->submit($eventCode, $eventQtde, $formData);
    }

    /** @return array<string, mixed> */
    public function serializeState(): array
    {
        return ViewStateSerializer::serialize($this->state, $this->instanceId);
    }
}
