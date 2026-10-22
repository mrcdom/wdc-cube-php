<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Wdc\Framework\Commons\Concurrent\Registration;
use Wdc\Framework\Commons\Concurrent\Scheduler;

/**
 * As sessões vivas do processo, por appId, e as tarefas de fundo:
 * - a cada 50 ms, envia as views sujas das sessões que mudaram (push sem requestId);
 * - a cada 30 s, libera sessões expiradas que já não têm conexão.
 */
final class RemoteApplicationRegistry
{
    public const float FLUSH_INTERVAL_SECONDS = 0.05;
    public const float EXPIRY_CHECK_INTERVAL_SECONDS = 30.0;
    public const int DEFAULT_SESSION_TTL_SECONDS = 180;
    private const float WAKEUP_MIN_GAP_MS = 10.0;
    private const float WAKEUP_FAST_GAP_MS = 16.0;

    /** @var array<string, RemoteApplication> */
    private array $instances = [];

    /** @var array<string, RemoteApplication> sessões com views sujas, à espera do flush */
    private array $dirtyQueue = [];

    /** @var list<Registration> */
    private array $tasks = [];

    private ?Scheduler $scheduler = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param Closure(string, array<string, mixed>): RemoteApplication $factory cria a app na 1ª mensagem
     * @param int $maxInstances       -1 = sem limite
     * @param int $sessionTtlSeconds  0 = libera a sessão assim que a conexão cai
     * @param (Closure(): float)|null $clock relógio em milissegundos (testes)
     * @param KeyDerivation $keyDerivation como as sessões derivam a chave do canal (PBKDF2)
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
        if ($this->isFull()) {
            throw new CapacityExceededException($this->maxInstances);
        }

        return $this->instances[$appId] = ($this->factory)($appId, $request);
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
     * Durante um submit: agenda um flush "já" (próxima volta do event loop), no máximo um a
     * cada 10–16 ms. Só tem efeito se o submit ceder a vez — p.ex. esperando I/O.
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

    /** Push de fundo de todas as sessões sujas. */
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

    /** Expiradas sem conexão são liberadas; expiradas ainda conectadas ganham mais prazo. */
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
