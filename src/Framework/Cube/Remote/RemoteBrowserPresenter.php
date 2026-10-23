<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\PresenterBase;
use Wdc\Framework\Cube\Skeleton;

/**
 * The root view of the protocol (fixed id `7b32e816a191:0`): hosts the root presenter's view,
 * shows alerts and receives the browser's navigation events.
 *
 * Events: -1 start (p.path), -2 back/forward (p.path), 1 alert OK, 2 keep-alive.
 */
final class RemoteBrowserPresenter implements PresenterBase
{
    public const string CLASS_ID = '7b32e816a191';

    public readonly BrowserState $state;

    public readonly RemoteView $view;

    /** @var Closure(): void actions to run when the user closes the alert */
    private Closure $alertAction;

    public function __construct(
        private readonly RemoteApplication $app,
        private readonly LoggerInterface $logger,
    ) {
        $this->state = new BrowserState();
        $this->alertAction = static function (): void {
        };
        $this->view = new RemoteView($app, $this, $this->state, $this->skeleton(), self::CLASS_ID . ':0');
        $this->state->instanceId = $this->view->instanceId;
    }

    public function commitComputedState(): void
    {
        $this->state->contentView = $this->app->rootPresenterView();
    }

    public function release(): void
    {
        $this->view->release();
    }

    public function update(): void
    {
        $this->app->remote()->markDirty($this->view);
    }

    public function alertUnexpectedError(?string $message, ?Throwable $cause): void
    {
        $message ??= $cause?->getMessage() ?? 'An unexpected error occurred';
        $this->logger->error($message, $cause === null ? [] : ['exception' => $cause]);
        $none = static function (): void {
        };
        if ($cause !== null) {
            $this->alert($none, -1, $message, (string) $cause);   // (string) $e = message + stack trace
        } else {
            $this->alert($none, -1, $message);
        }
    }

    /** @param Closure(): void $action run on OK, after the actions of previous alerts */
    public function alert(Closure $action, int $code, string ...$args): void
    {
        $previous = $this->alertAction;
        $this->alertAction = static function () use ($previous, $action): void {
            $previous();
            $action();
        };
        $this->state->alertId = $code;
        $this->state->alertArgs = array_values($args);
        $this->state->alertMessage = new AlertMessage($code, array_values($args));
        $this->update();
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton(self::CLASS_ID, function (int $event, int $count, array $form): void {
            match ($event) {
                1 => $this->onAlertOk(),
                2 => $this->app->remote()->extendLife(),
                -1 => $this->onStart(Coerce::asString($form['p.path'] ?? null)),
                -2 => $this->onHistoryChanged(Coerce::asString($form['p.path'] ?? null)),
                default => Skeleton::unknownEvent($event),
            };
        });
    }

    private function onStart(?string $path): void
    {
        try {
            $this->app->remote()->safeGo($path);
            $this->app->remote()->updateAllViews();
        } catch (Throwable $e) {
            $this->logger->warning('onStart', ['exception' => $e]);
        }
    }

    private function onHistoryChanged(?string $path): void
    {
        try {
            $this->app->remote()->safeGo($path);
        } catch (Throwable $e) {
            $this->logger->warning('onHistoryChanged', ['exception' => $e]);
        }
    }

    private function onAlertOk(): void
    {
        try {
            $this->state->alertId = 0;
            $this->state->alertArgs = [];
            $this->state->alertMessage = null;
            ($this->alertAction)();
        } finally {
            $this->alertAction = static function (): void {
            };
            $this->update();
        }
    }
}
