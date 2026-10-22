<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

use Wdc\Shopping\Presentation\Presenter\RootKeys;
use Closure;
use LogicException;
use Throwable;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Cube\AbstractCubePresenter;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeSkeleton;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Framework\Cube\Skeleton;
use Wdc\Framework\Domain\Exception\OfflineException;
use Wdc\Shopping\Presentation\Routes;
use Wdc\Shopping\Presentation\ShoppingApplication;
use Wdc\Shopping\Presentation\StorageKeys;

/**
 * @extends AbstractCubePresenter<ShoppingApplication>
 */
final class LoginPresenter extends AbstractCubePresenter
{
    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly LoginViewState $state;

    private readonly LoginService $loginService;

    private ?CubeViewSlot $ownerSlot = null;

    public function __construct(ShoppingApplication $app)
    {
        parent::__construct($app);
        $this->state = new LoginViewState();
        $this->loginService = new LoginService($app->userRepository(), $app->authService());
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        if ($initialization) {
            $this->view = (self::$createView ?? throw new LogicException('LoginPresenter::$createView não configurado'))($this);
        }
        $this->ownerSlot = (new RootKeys($this->app, $intent))->ownerSlot;   // a cada viagem: quem decide o slot é o dono acima
        $this->ownerSlot?->setView($this->view);

        return true;
    }

    public function onEnter(?string $userName, ?string $password): void
    {
        $this->state->userName = $userName;
        $this->state->loading = true;
        $this->state->errorCode = 0;
        $this->state->errorMessage = null;
        $this->update();

        if ($this->app->context->simulateSlowLogin) {
            ($this->app->context->sleep)(3.0);   // sleep assíncrono: não pode travar o event loop
        }

        try {
            $result = $this->loginService->fetchSubject($userName, $password);
            if ($result === null) {
                $this->app->setSubject(null);
                $this->alertUserOrPasswordNotRecognize();
            } else {
                if ($result->securityContext !== null) {
                    $this->app->setSecurityContext($result->securityContext);
                }
                if ($result->persistentToken !== null) {
                    $this->app->clientPersistentStore()->secure()->set(StorageKeys::AUTH_TOKEN, $result->persistentToken);
                }
                $this->app->setSubject($result->subject);
                Routes::home($this->app);
            }
        } catch (OfflineException) {
            $this->alertDatabaseIsOffline();
        } catch (Throwable $caught) {
            $this->alertConnectionError($caught);
        } finally {
            $this->state->loading = false;
            $this->update();
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton(
            'c677cda52d14',
            function (int $event, int $qtde, array $form): void {
                match ($event) {
                    1 => $this->onEnter(
                        Coerce::asString($form['p.userName'] ?? null),
                        $this->app->b64Decipher(Coerce::asString($form['p.password'] ?? null) ?? ''),
                    ),
                    default => Skeleton::unknownEvent($event),
                };
            },
            function (array $form): void {
                if (array_key_exists('userName', $form)) {
                    $this->state->userName = Coerce::asString($form['userName']);
                }
            },
        );
    }

    private function alertUserOrPasswordNotRecognize(): void
    {
        $this->state->errorCode = 1;
        $this->state->errorMessage = 'Usuário ou senha não reconhecido!';
        $this->update();
    }

    private function alertDatabaseIsOffline(): void
    {
        $this->state->errorCode = 4;
        $this->state->errorMessage = 'Banco de dados esta fora do ar!';
        $this->update();
    }

    private function alertConnectionError(Throwable $caught): void
    {
        $message = 'Falha de comunicação com o servidor. Verifique sua conexão.';
        $this->state->errorCode = 5;
        $this->state->errorMessage = $message;
        $this->update();
        $this->app->logger()->error($message, ['exception' => $caught]);
    }
}
