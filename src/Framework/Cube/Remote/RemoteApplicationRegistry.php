<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Amp\Sync\LocalKeyedMutex;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Wdc\Framework\Commons\Concurrent\Registration;
use Wdc\Framework\Commons\Concurrent\Scheduler;

/**
 * The live sessions of the process, by appId, and the background tasks:
 * - every 50 ms, sends the dirty views of sessions that changed (push without requestId);
 * - every 30 s, releases expired sessions that no longer have a connection.
 */
final class RemoteApplicationRegistry
{
    private ?LocalKeyedMutex $creating = null;

    public const float FLUSH_INTERVAL_SECONDS = 0.05;
    public const float EXPIRY_CHECK_INTERVAL_SECONDS = 30.0;
    public const int DEFAULT_SESSION_TTL_SECONDS = 180;
    private const float WAKEUP_MIN_GAP_MS = 10.0;
    private const float WAKEUP_FAST_GAP_MS = 16.0;

    /** @var array<string, RemoteApplication> */
    private array $instances = [];

    /** @var array<string, RemoteApplication> sessions with dirty views, waiting for the flush */
    private array $dirtyQueue = [];

    /** @var list<Registration> */
    private array $tasks = [];

    private ?Scheduler $scheduler = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param Closure(string, array<string, mixed>): RemoteApplication $factory creates the app on the first message
     * @param int $maxInstances       -1 = unlimited
     * @param int $sessionTtlSeconds  0 = release the session as soon as the connection drops
     * @param (Closure(): float)|null $clock clock in milliseconds (tests)
     * @param KeyDerivation $keyDerivation how sessions derive the channel key (PBKDF2)
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly int $maxInstances = -1,
        private readonly int $sessionTtlSeconds = self::DEFAULT_SESSION_TTL_SECONDS,
        ?Closure $clock = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        public readonly KeyDerivation $keyDerivation = new DirectKeyDerivation(),
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true) * 1000;
    }

    public function init(Scheduler $scheduler): void
    {
        if ($this->scheduler !== null) {
            return;
        }
        $this->scheduler = $scheduler;
        $this->tasks[] = $scheduler->scheduleAtFixedRate($this->flushAll(...), self::FLUSH_INTERVAL_SECONDS, self::FLUSH_INTERVAL_SECONDS);
        $this->tasks[] = $scheduler->scheduleAtFixedRate($this->removeExpired(...), self::EXPIRY_CHECK_INTERVAL_SECONDS, self::EXPIRY_CHECK_INTERVAL_SECONDS);
    }

    public function stop(): void
    {
        foreach ($this->tasks as $task) {
            $task->remove();
        }
        $this->tasks = [];
        $this->scheduler = null;
    }

    public function now(): float
    {
        return ($this->clock)();
    }

    public function sessionTtlSeconds(): int
    {
        return $this->sessionTtlSeconds;
    }

    public function isImmediateRelease(): bool
    {
        return $this->sessionTtlSeconds === 0;
    }

    public function size(): int
    {
        return count($this->instances);
    }

    public function isFull(): bool
    {
        return $this->maxInstances > 0 && count($this->instances) >= $this->maxInstances;
    }

    public function get(string $appId): ?RemoteApplication
    {
        return $this->instances[$appId] ?? null;
    }

    /** @param array<string, mixed> $request */
    public function getOrCreate(string $appId, array $request): RemoteApplication
    {
        if (isset($this->instances[$appId])) {
            return $this->instances[$appId];
        }
        // creating the session queries the database (suspends the Fiber): without the per-appId
        // lock, two connections with the same id would create two sessions
        $lock = ($this->creating ??= new LocalKeyedMutex())->acquire($appId);
        try {
            if (isset($this->instances[$appId])) {
                return $this->instances[$appId];
            }
            if ($this->isFull()) {
                throw new CapacityExceededException($this->maxInstances);
            }

            return $this->instances[$appId] = ($this->factory)($appId, $request);
        } finally {
            $lock->release();
        }
    }

    public function remove(string $appId): void
    {
        unset($this->instances[$appId], $this->dirtyQueue[$appId]);
    }

    public function enqueueDirty(RemoteApplication $app): void
    {
        $support = $app->remote();
        if (!$support->dirtyQueued) {
            $support->dirtyQueued = true;
            $this->dirtyQueue[$support->id] = $app;
        }
    }

    /**
     * During a submit: schedules an "immediate" flush (next event loop tick), at most one every
     * 10–16 ms. Only has an effect if the submit yields — e.g. while waiting for I/O.
     */
    public function triggerImmediateFlush(RemoteApplication $app): void
    {
        $support = $app->remote();
        $now = $this->now();
        $gap = $support->isProcessingSubmit() ? self::WAKEUP_FAST_GAP_MS : self::WAKEUP_MIN_GAP_MS;
        if ($this->scheduler === null || $now - $support->lastWakeupMs < $gap) {
            return;
        }
        $support->lastWakeupMs = $now;
        $this->scheduler->schedule(function () use ($support): void {
            try {
                $support->flushDirtyViews();
            } catch (Throwable $e) {
                $this->logger->error("Immediate flush error for app {$support->id}", ['exception' => $e]);
            }
        }, 0.0);
    }

    /** Background push of all dirty sessions. */
    public function flushAll(): void
    {
        $queue = $this->dirtyQueue;
        $this->dirtyQueue = [];
        foreach ($queue as $app) {
            try {
                $app->remote()->flushDirtyViews();
            } catch (Throwable $e) {
                $this->logger->error("Background flush error for app {$app->remote()->id}", ['exception' => $e]);
            }
        }
    }

    /** Expired sessions without a connection are released; expired ones still connected get more time. */
    public function removeExpired(): void
    {
        $now = $this->now();
        foreach ($this->instances as $app) {
            if ($app->remote()->isExpired($now)) {
                if ($app->remote()->channel() === null) {
                    $app->release();
                } else {
                    $app->remote()->extendLife();
                }
            }
        }
    }
}
