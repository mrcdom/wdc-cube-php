<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Server side of the view "RPC": receives the events the client fires.
 */
interface CubeSkeleton
{
    /** Stable screen identifier (12 hex), shared with the client. */
    public function classId(): string;

    /**
     * @param array<string, mixed> $formData "p.xxx" fields are event parameters
     */
    public function submit(int $eventCode, int $eventCount, array $formData): void;

    /**
     * Copies into the ViewState the fields the client syncs (without the "p." prefix).
     *
     * @param array<string, mixed> $formData
     */
    public function syncState(array $formData): void;
}
