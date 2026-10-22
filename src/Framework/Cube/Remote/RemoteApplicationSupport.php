<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Commons\Codec\Base62;
use Wdc\Framework\Commons\Concurrent\ReentrantLock;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\Util\QueryString;

/**
 * A infraestrutura remota de uma sessão: views vivas e
 * sujas, despacho de requests, montagem das respostas, assinatura da URL, cifra do canal,
 * client storage e tempo de vida.
 *
 * Modelo de execução: um único event loop, mas as consultas ao banco suspendem a Fiber que as
 * faz — e nesse meio-tempo um timer ou o flush de fundo da mesma sessão pode rodar. Por isso a
 * navegação, a sincronização dos formulários, o cálculo do estado e o alerta de erro passam por
 * um lock da sessão. O evento em si não: um evento demorado (ex.: login lento) deixa o flush de
 * fundo mandar ao cliente o estado intermediário ("aguarde...").
 */
final class RemoteApplicationSupport
{
    public const int ACTIVE_VIEWS_INTERVAL_MS = 5 * 60 * 1000;

    public readonly RemoteDataSecurity $dataSecurity;

    public readonly RemoteClientStorage $clientStorage;

    private ?RemoteBrowserPresenter $browser = null;

    private ?RemoteChannel $channel = null;

    /** @var array<string, RemoteView> */
    private array $views = [];

    /** @var array<string, RemoteView> */
    private array $dirtyViews = [];

    /** @var list<string> ids de views liberadas, para avisar o cliente no próximo ping */
    private array $releasedViews = [];

    private int $instanceIdGen = 1;

    private int $lastRequestId = -1;

    private float $lastActiveViewsSentAt;

    private ?string $lastSentFragment = null;

    private bool $historyDirty = false;

    private bool $navigationAttempted = false;

    private bool $processingSubmit = false;

    private float $expireMoment;

    private readonly ReentrantLock $lock;

    private int $timeSpanSeconds;

    /** @internal usados pelo registry para não enfileirar nem acordar a mesma sessão duas vezes */
    public bool $dirtyQueued = false;

    /** @internal */
    public float $lastWakeupMs = 0.0;

    public function __construct(
        public readonly string $id,
        private readonly RemoteApplication $owner,
        private readonly RemoteAppSecurity $security,
        private readonly RemoteApplicationRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
        $this->lock = new ReentrantLock();
        $this->timeSpanSeconds = $registry->sessionTtlSeconds();
        $this->expireMoment = $this->now() + $this->timeSpanSeconds * 1000;
        $this->lastActiveViewsSentAt = $this->now();
        $this->dataSecurity = new RemoteDataSecurity($security, $logger, $registry->keyDerivation);
        $this->clientStorage = new RemoteClientStorage($this->dataSecurity);
    }

    /** Chamar depois que o construtor da app terminou (a Browser view precisa da app pronta). */
    public function postConstruct(): void
    {
        $this->browser = new RemoteBrowserPresenter($this->owner, $this->logger);
        $this->browser->update();
    }

    public function browser(): RemoteBrowserPresenter
    {
        return $this->browser ?? throw new LogicException('postConstruct() não foi chamado');
    }

    // :: Tempo de vida

    public function setTimeSpan(int $seconds): void
    {
        $this->timeSpanSeconds = $seconds;
    }

    public function extendLife(): void
    {
        $this->expireMoment = $this->now() + $this->timeSpanSeconds * 1000;
    }

    public function isExpired(float $nowMs): bool
    {
        return $this->expireMoment < $nowMs;
    }

    public function release(): void
    {
        $this->browser?->release();
    }

    // :: Conexão

    public function channel(): ?RemoteChannel
    {
        return $this->channel;
    }

    public function setChannel(?RemoteChannel $channel): void
    {
        $this->channel = $channel;
    }

    public function clientIp(): ?string
    {
        return $this->channel?->clientIp();
    }

    /**
     * O cliente reconectou (queda de rede, F5) com a sessão ainda viva: tudo é reenviado.
     */
    public function resetForReconnect(): void
    {
        $this->lastSentFragment = null;
        $this->historyDirty = true;
        $this->releasedViews = [];
        $this->lastActiveViewsSentAt = $this->now();
        $this->updateAllViews();
    }

    // :: Views

    public function nextInstanceId(): int
    {
        return $this->instanceIdGen++;
    }

    public function view(string $instanceId): ?RemoteView
    {
        return $this->views[$instanceId] ?? null;
    }

    /** @return array<string, RemoteView> */
    public function views(): array
    {
        return $this->views;
    }

    public function putView(RemoteView $view): void
    {
        $this->views[$view->instanceId] = $view;
    }

    public function removeView(string $instanceId): void
    {
        unset($this->dirtyViews[$instanceId]);
        if (isset($this->views[$instanceId])) {
            unset($this->views[$instanceId]);
            $this->releasedViews[] = $instanceId;
        }
    }

    public function markDirty(RemoteView $view): void
    {
        $this->dirtyViews[$view->instanceId] = $view;
        $this->registry->enqueueDirty($this->owner);
        if ($this->processingSubmit) {
            // durante um submit longo (que cede a vez, ex.: espera), manda o que já mudou
            $this->registry->triggerImmediateFlush($this->owner);
        }
    }

    public function updateAllViews(): void
    {
        foreach ($this->views as $view) {
            $this->markDirty($view);
        }
    }

    // :: Histórico e navegação

    public function updateHistory(): void
    {
        $this->historyDirty = true;
    }

    /** Monta a URL do estado atual e a assina: `place?params&sign=<base62(md5(rsa(url)))>`. */
    public function doUpdateHistory(): void
    {
        if (!$this->historyDirty) {
            return;
        }
        $app = $this->owner->cubeApp();
        $intent = new CubeIntent();
        $intent->setPlace($app->lastPlace() ?? $app->rootPlace());
        $app->publishParameters($intent);
        $intent->setParameter('sign', $this->signatureOf($intent));
        $app->setFragment((string) $intent);
        $this->historyDirty = false;
        $this->navigationAttempted = true;
    }

    /**
     * Navega para um caminho vindo do cliente. Assinatura inválida (URL editada à mão, link
     * velho, boot sem hash) ⇒ ignora o caminho e reafirma o estado atual.
     */
    public function safeGo(?string $path): void
    {
        $app = $this->owner->cubeApp();
        $intent = CubeIntent::parse($path);
        $actual = QueryString::asString($intent->removeParameter('sign')) ?? 'null';   // ausente = "null" (o texto), como o protocolo espera
        if (!hash_equals($this->signatureOf($intent), $actual)) {
            $this->updateHistory();
            $intent = $app->newIntent();
            if ($intent->place() === null) {
                $intent->setPlace($app->rootPlace());
            }
        }
        $this->lock->run(fn () => $this->owner->performGo($intent));
    }

    public function alertUnexpectedError(string $message, Throwable $e): void
    {
        $this->lock->run(fn () => $this->browser()->alertUnexpectedError($message, $e));
    }

    // :: Requests

    /**
     * Processa uma mensagem do cliente e responde.
     *
     * @param array<string, mixed> $request
     */
    public function sendResponse(array $request): void
    {
        $ping = $request['ping'] ?? false;
        if ($ping === true || $ping === 'true') {
            $this->extendLife();
            $this->applySecret($request);
            $this->sendPingResponseIfNeeded();

            return;
        }

        $requestId = Coerce::asInt($request['requestId'] ?? null);
        if ($requestId === null) {
            $this->logger->debug('Discarding request: requestId is null');

            return;
        }
        if ($requestId <= $this->lastRequestId) {   // reenvio de algo já processado
            $this->logger->debug("Discarding request: {$requestId} <= {$this->lastRequestId}");

            return;
        }

        $this->processingSubmit = true;
        try {
            $this->lastRequestId = $requestId;
            $this->dispatch($request);
            $this->writeAndSendResponse($requestId, $this->drainDirtyViews());
        } finally {
            $this->processingSubmit = false;
        }
    }

    /** Push de fundo: o registry chama a cada 50 ms para as sessões com views sujas. */
    public function flushDirtyViews(): void
    {
        $this->dirtyQueued = false;
        if ($this->dirtyViews === [] || $this->channel === null) {
            return;
        }
        try {
            $this->writeAndSendResponse(null, $this->drainDirtyViews());
        } catch (Throwable $e) {
            $this->logger->error('Error during background flush', ['exception' => $e]);
        }
        if ($this->dirtyViews !== []) {   // algo ficou sujo durante a serialização (commitComputedState)
            $this->registry->enqueueDirty($this->owner);
        }
    }

    public function isProcessingSubmit(): bool
    {
        return $this->processingSubmit;
    }

    // :: Internos

    /** @param array<string, mixed> $request */
    private function dispatch(array $request): void
    {
        $this->applySecret($request);

        // 1. campos sem prefixo de cada view sincronizam o estado
        $this->lock->run(function () use ($request): void {
            foreach ($request as $key => $formData) {
                $view = $this->views[(string) $key] ?? null;
                if ($view !== null && is_array($formData)) {
                    $view->syncClientToServer($formData);
                }
            }
        });

        // 2. eventos "<vsid>:<código>", repetidos viram contagem
        $events = $request['event'] ?? null;
        if (!is_array($events) || $events === []) {
            return;
        }
        $counts = [];
        foreach ($events as $event) {
            if (is_string($event)) {
                $counts[$event] = ($counts[$event] ?? 0) + 1;
            }
        }

        $viewNotFound = false;
        foreach ($counts as $event => $quantity) {
            $pos = strrpos((string) $event, ':');   // o id da view também tem ":" — vale o último
            if ($pos === false) {
                continue;
            }
            $viewId = substr((string) $event, 0, $pos);
            $view = $this->views[$viewId] ?? null;
            if ($view === null) {
                $viewNotFound = true;   // view que o cliente acha que existe: ressincroniza tudo
                continue;
            }
            try {
                $formData = $request[$viewId] ?? [];
                $view->submit((int) substr((string) $event, $pos + 1), $quantity, is_array($formData) ? $formData : []);
            } catch (Throwable $e) {
                $this->alertUnexpectedError('submitting event', $e);
                $view->update();
            }
        }
        if ($viewNotFound) {
            $this->updateAllViews();
        }
    }

    /** @param array<string, mixed> $request */
    private function applySecret(array $request): void
    {
        $secret = Coerce::asString($request['secret'] ?? null);
        if ($secret !== null && trim($secret) !== '') {
            $this->dataSecurity->updateSecret($secret);
        }
    }

    private function sendPingResponseIfNeeded(): void
    {
        $now = $this->now();
        $sendActive = ($now - $this->lastActiveViewsSentAt) >= self::ACTIVE_VIEWS_INTERVAL_MS;
        if ($this->releasedViews === [] && !$sendActive) {
            return;
        }
        $message = [];
        if ($this->releasedViews !== []) {
            $message['releasedViews'] = $this->releasedViews;
            $this->releasedViews = [];
        }
        if ($sendActive) {
            $this->lastActiveViewsSentAt = $now;
            $message['activeViews'] = array_keys($this->views);
        }
        $this->sendText(ViewStateSerializer::json($message));
    }

    /** @return list<RemoteView> */
    private function drainDirtyViews(): array
    {
        $views = array_values($this->dirtyViews);
        $this->dirtyViews = [];

        return $views;
    }

    /**
     * Envelope: `{requestId?, storage?, uri?, states?}` (nesta ordem). Nada sai se estiver vazio.
     *
     * @param list<RemoteView> $dirtyViews
     */
    private function writeAndSendResponse(?int $requestId, array $dirtyViews): void
    {
        if ($requestId === null && $dirtyViews === [] && !$this->historyDirty) {
            return;
        }
        $this->doUpdateHistory();

        $response = [];
        if ($requestId !== null) {
            $response['requestId'] = $requestId;
        }
        $storage = $this->clientStorage->drainDeltas();
        if ($storage !== null) {
            // (object): garante objeto JSON mesmo com chaves que parecem números
            $response['storage'] = (object) array_map(static fn (array $scope): object => (object) $scope, $storage);
        }
        $fragment = $this->owner->cubeApp()->fragment();
        if ($fragment !== null && ($this->navigationAttempted || $fragment !== $this->lastSentFragment)) {
            $this->lastSentFragment = $fragment;
            $this->navigationAttempted = false;
            $response['uri'] = $fragment;
        }
        if ($dirtyViews !== []) {
            $states = [];
            foreach ($dirtyViews as $view) {
                $this->lock->run($view->presenter->commitComputedState(...));   // estado derivado, logo antes de serializar
                $states[] = $view->serializeState();
            }
            $response['states'] = $states;
        }

        if ($response !== []) {
            $this->sendText(ViewStateSerializer::json($response));
        }
    }

    private function sendText(string $text): void
    {
        ($this->channel ?? throw new LogicException('Missing channel'))->send($text);
    }

    private function signatureOf(CubeIntent $intent): string
    {
        return Base62::encode($this->security->signAsHash((string) $intent));
    }

    private function now(): float
    {
        return $this->registry->now();
    }
}
