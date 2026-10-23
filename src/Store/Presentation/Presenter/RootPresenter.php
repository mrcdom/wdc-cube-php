<?php

declare(strict_types=1);

namespace Wdc\Store\Presentation\Presenter;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Store\Domain\User\UserCriteria;
use Wdc\Store\Domain\User\UserRepository;
use Wdc\Store\Presentation\Exception\WrongPlace;
use Wdc\Store\Presentation\Presenter\Open\Login\Subject;
use Wdc\Store\Presentation\StoreApplication;
use Wdc\Store\Presentation\StorageKeys;

/**
 * Root: hosts the current screen (login or home) and shows unexpected errors.
 *
 * @extends AbstractCubePresenter<StoreApplication>
 */
final class RootPresenter extends AbstractCubePresenter
{
    /**
     * View factory, filled in by the host (or by the test).
     *
     * @var (Closure(self): CubeView)|null
     */
    public static ?Closure $createView = null;

    public readonly RootViewState $state;

    private readonly CubeViewSlot $contentSlot;

    public function __construct(StoreApplication $app)
    {
        parent::__construct($app);
        $this->state = new RootViewState();
        $this->contentSlot = new CubeViewSlot($this->setContentView(...));
    }

    public function release(): void
    {
        $this->state->contentView = null;
        parent::release();
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        if ($initialization) {
            $this->view = (self::$createView ?? throw new LogicException('RootPresenter::$createView not configured'))($this);
            $this->tryAutoLogin();
        }
        if ($deepest) {
            throw new WrongPlace();
        }
        // does not accept switching the user through the URL
        $keys = new RootKeys($this->app, $intent);
        $subject = $this->app->subject();
        if ($subject !== null) {
            $keys->userId = $subject->id;
        }
        $keys->ownerSlot = $this->contentSlot;   // offered on every trip: the level below always reads it

        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        $subject = $this->app->subject();
        if ($subject !== null) {
            $keys = new RootKeys($this->app, $intent);
            $keys->userId = $subject->id;
        }
    }

    public function alertUnexpectedError(string $message, Throwable $caught): void
    {
        $detail = trim($caught->getMessage());
        $text = $detail === '' ? $message : "{$message}: {$detail}";
        $this->state->errorMessage = $text;
        $this->update();
        $this->app->logger()->error($text, ['exception' => $caught]);
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('f2d345c4a610', static function (): void {
            // the root receives no events
        });
    }

    /**
     * Auto-login: the persistent ("remember me") token kept in the client's secure storage.
     */
    private function tryAutoLogin(): void
    {
        $storage = $this->app->clientPersistentStore()->secure();
        $token = $storage->get(StorageKeys::AUTH_TOKEN);
        $auth = Beans::find(AuthenticationService::class);
        if ($token === null || trim($token) === '' || $auth === null) {
            return;
        }

        $result = $auth->loginWithPersistentToken($token);
        if ($result === null) {
            $this->app->logger()->info('Auto-login failed: invalid or expired token');
            $storage->remove(StorageKeys::AUTH_TOKEN);

            return;
        }

        $user = Beans::of(UserRepository::class)->fetch(
            new UserCriteria()->withUserId($result->userId)->withProjection(Subject::projection()),
            0,
            1,
        )[0] ?? null;
        $subject = Subject::create($user);
        if ($subject !== null) {
            $this->app->setSubject($subject);
            $this->app->logger()->info("Auto-login successful for user: {$subject->id}");
        }
    }

    private function setContentView(?CubeView $view): void
    {
        if ($this->state->contentView !== $view) {
            $this->state->contentView = $view;
            $this->update();
        }
    }
}
