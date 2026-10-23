<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\RemoteHost;

use Wdc\Framework\Cube\Remote\RemoteConnection;

/** Test connection: records what the server sent and how it closed. */
final class RecordingConnection implements RemoteConnection
{
    private static int $ids = 0;

    /** @var list<string> */
    public array $sent = [];

    public ?int $closeCode = null;

    public ?string $closeReason = null;

    private readonly string $id;

    public function __construct()
    {
        $this->id = 'conn-' . ++self::$ids;
    }

    public function send(string $text): void
    {
        $this->sent[] = $text;
    }

    public function clientIp(): string
    {
        return '10.0.0.7';
    }

    public function connectionId(): string
    {
        return $this->id;
    }

    public function close(int $code, string $reason): void
    {
        $this->closeCode = $code;
        $this->closeReason = $reason;
    }
}
