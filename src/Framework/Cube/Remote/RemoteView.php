<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\PresenterBase;
use Wdc\Framework\Cube\ViewState;

/**
 * The generic view of remote mode: it draws nothing — it holds the ViewState to be
 * serialized and forwards events to the skeleton. A single class serves every screen;
 * the React shell picks the component by the id prefix (`<classId>:<n>`).
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
        $app->remote()->markDirty($this);   // every view is born "dirty": it must be sent to the client
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
    public function submit(int $eventCode, int $eventCount, array $formData): void
    {
        $this->skeleton->submit($eventCode, $eventCount, $formData);
    }

    /** @return array<string, mixed> */
    public function serializeState(): array
    {
        return ViewStateSerializer::serialize($this->state, $this->instanceId);
    }
}
