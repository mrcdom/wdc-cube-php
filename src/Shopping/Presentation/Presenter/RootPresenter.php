<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter;

use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Shopping\Domain\User\UserCriteria;
use Wdc\Shopping\Presentation\Exception\WrongPlace;
use Wdc\Shopping\Presentation\PlaceAttributes;
use Wdc\Shopping\Presentation\PlaceParameters;
use Wdc\Shopping\Presentation\Presenter\Open\Login\Subject;
use Wdc\Shopping\Presentation\ShoppingApplication;
use Wdc\Shopping\Presentation\StorageKeys;

/**
 * Raiz: hospeda a tela corrente (login ou home) e mostra erros inesperados.
 *
 * @extends AbstractCubePresenter<ShoppingApplication>
 */
final class RootPresenter extends AbstractCubePresenter
{
    /**
     * Fábrica da view, preenchida pelo host (ou pelo teste).
     *
     * @var (Closure(self): CubeView)|null
     */
    public static ?Closure $createView = null;

    public readonly RootViewState $state;

    private readonly CubeViewSlot $contentSlot;

    public function __construct(ShoppingApplication $app)
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
            $this->view = (self::$createView ?? throw new LogicException('RootPresenter::$createView não configurado'))($this);
            $this->tryAutoLogin();
        }
        if ($deepest) {
            throw new WrongPlace();
        }
        // não aceita trocar o usuário pela URL
        $subject = $this->app->subject();
        if ($subject !== null) {
            $intent->setParameter(PlaceParameters::USER_ID, $subject->id);
        }
        $intent->setViewSlot(PlaceAttributes::SLOT_OWNER, $this->contentSlot);

        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        $subject = $this->app->subject();
        if ($subject !== null) {
            $intent->setParameter(PlaceParameters::USER_ID, $subject->id);
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
            // a raiz não recebe eventos
        });
    }

    /**
     * Auto-login: o token persistente ("lembrar-me") guardado no armazenamento seguro do cliente.
     *
     * O SecurityContext resolvido fica só na sessão, não no contexto global
     * `SecurityContext.CURRENT` — num processo com várias sessões,
     * gravá-lo no contexto global o deixaria visível às outras. Os repositórios da sessão o
     * aplicam a cada chamada.
     */
    private function tryAutoLogin(): void
    {
        $storage = $this->app->clientPersistentStore()->secure();
        $token = $storage->get(StorageKeys::AUTH_TOKEN);
        $auth = $this->app->authService();
        if ($token === null || trim($token) === '' || $auth === null) {
            return;
        }

        $result = $auth->loginWithPersistentToken($token);
        if ($result === null) {
            $this->app->logger()->info('Auto-login failed: invalid or expired token');
            $storage->remove(StorageKeys::AUTH_TOKEN);

            return;
        }

        $securityContext = $auth->resolveToken($result->accessToken);
        if ($securityContext !== null) {
            $this->app->setSecurityContext($securityContext);
        }
        $user = $this->app->userRepository()->fetch(
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
