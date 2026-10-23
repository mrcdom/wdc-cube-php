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
 * The remote infrastructure of a session: live and dirty views, request dispatch, response
 * assembly, URL signing, channel encryption, client storage and lifetime.
 *
 * Execution model: a single event loop, but database queries suspend the Fiber that issues
 * them — and meanwhile a timer or the background flush of the same session may run. That is why
 * navigation, form synchronization, state computation and the error alert go through a session
 * lock. The event itself does not: a slow event (e.g. a slow login) lets the background flush
 * send the intermediate state ("please wait...") to the client.
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

    /** @var list<string> ids of released views, to notify the client on the next ping */
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

    /** @internal used by the registry so the same session is not queued or woken up twice */
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

    /** Call after the app constructor has finished (the Browser view needs the app to be ready). */
    public function postConstruct(): void
    {
        $this->browser = new RemoteBrowserPresenter($this->owner, $this->logger);
        $this->browser->update();
    }

    public function browser(): RemoteBrowserPresenter
    {
        return $this->browser ?? throw new LogicException('postConstruct() was not called');
    }

    // :: Lifetime

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

    // :: Connection

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
     * The client reconnected (network drop, F5) while the session is still alive: everything is resent.
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
            // during a long submit (one that yields, e.g. while waiting), send what has already changed
            $this->registry->triggerImmediateFlush($this->owner);
        }
    }

    public function updateAllViews(): void
    {
        foreach ($this->views as $view) {
            $this->markDirty($view);
        }
    }

    // :: History and navigation

    public function updateHistory(): void
    {
        $this->historyDirty = true;
    }

    /** Builds the URL of the current state and signs it: `place?params&sign=<base62(md5(rsa(url)))>`. */
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
     * Navigates to a path coming from the client. Invalid signature (hand-edited URL, stale
     * link, boot without hash) ⇒ ignores the path and reasserts the current state.
     */
    public function safeGo(?string $path): void
    {
        $app = $this->owner->cubeApp();
        $intent = CubeIntent::parse($path);
        $actual = QueryString::asString($intent->removeParameter('sign')) ?? 'null';   // missing = "null" (the text), as the protocol expects
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
     * Processes a client message and responds.
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
        if ($requestId <= $this->lastRequestId) {   // resend of something already processed
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

    /** Background push: the registry calls it every 50 ms for sessions with dirty views. */
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
        if ($this->dirtyViews !== []) {   // something became dirty during serialization (commitComputedState)
            $this->registry->enqueueDirty($this->owner);
        }
    }

    public function isProcessingSubmit(): bool
    {
        return $this->processingSubmit;
    }

    // :: Internals

    /** @param array<string, mixed> $request */
    private function dispatch(array $request): void
    {
        $this->applySecret($request);

        // 1. each view's unprefixed fields synchronize the state
        $this->lock->run(function () use ($request): void {
            foreach ($request as $key => $formData) {
                $view = $this->views[(string) $key] ?? null;
                if ($view !== null && is_array($formData)) {
                    $view->syncClientToServer($formData);
                }
            }
        });

        // 2. "<vsid>:<code>" events; repeated ones become a count
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
            $pos = strrpos((string) $event, ':');   // the view id also contains ":" — the last one wins
            if ($pos === false) {
                continue;
            }
            $viewId = substr((string) $event, 0, $pos);
            $view = $this->views[$viewId] ?? null;
            if ($view === null) {
                $viewNotFound = true;   // a view the client thinks exists: resynchronize everything
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
     * Envelope: `{requestId?, storage?, uri?, states?}` (in this order). Nothing is sent if it is empty.
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
            // (object): ensures a JSON object even with keys that look like numbers
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
                $this->lock->run($view->presenter->commitComputedState(...));   // derived state, right before serializing
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
