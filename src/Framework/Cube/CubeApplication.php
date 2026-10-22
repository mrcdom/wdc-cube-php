<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Wdc\Framework\Commons\Storage\ClientStorage;

/**
 * Raiz de uma sessão: guarda os presenters ativos (um por place da cadeia corrente),
 * os atributos da sessão e o último place navegado.
 *
 * Threading: tudo roda num único event loop — nada roda em paralelo, então arrays
 * comuns bastam e não há locks. Uma Fiber só cede a vez em pontos explícitos de espera (I/O, delay).
 */
abstract class CubeApplication
{
    /** Acima disto, redirecionamentos aninhados são tratados como laço. */
    public const int MAX_REFLOW = 10;

    /** @var array<int, CubePresenter> id do place → presenter */
    private array $presenters = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    protected ?CubePlace $lastPlace = null;

    protected ?string $fragment = null;

    private ?CubeNavigation $navigation = null;

    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    abstract public function rootPlace(): CubePlace;

    /** Chamado ao fim de toda navegação (commit ou rollback): é onde a URL é reescrita. */
    abstract public function updateHistory(): void;

    /** Armazenamento de sessão (vive enquanto a app vive). */
    abstract public function clientSessionStore(): ClientStorage;

    /** Armazenamento persistente; `secure()` para a variante cifrada. */
    abstract public function clientPersistentStore(): ClientStorage;

    /** Libera todos os presenters, da folha para a raiz. */
    public function release(): void
    {
        $presenters = $this->presenters;
        $this->presenters = [];
        $this->releaseAll($presenters);
    }

    public function lastPlace(): ?CubePlace
    {
        return $this->lastPlace;
    }

    public function fragment(): ?string
    {
        return $this->fragment;
    }

    public function setFragment(?string $fragment): void
    {
        $this->fragment = $fragment;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        foreach ($this->presenters as $presenter) {
            $presenter->publishParameters($intent);
        }
    }

    public function commitComputedState(): void
    {
        foreach ($this->presenters as $presenter) {
            try {
                $presenter->commitComputedState();
            } catch (Throwable $e) {
                $this->logger->error('commitComputedState em ' . $presenter::class . ': ' . $e->getMessage());
            }
        }
    }

    /** Intent que descreve o estado atual: último place + parâmetros publicados. */
    public function newIntent(): CubeIntent
    {
        $intent = new CubeIntent();
        $intent->setPlace($this->lastPlace);
        $this->publishParameters($intent);

        return $intent;
    }

    /**
     * Começa uma navegação: `$app->navigate()->step(A)->step(B)->execute($intent)`.
     *
     * Se outra navegação estiver em curso (um presenter redirecionou de dentro do seu
     * applyParameters), ela é interrompida e a nova herda os presenters que ela já criou.
     */
    public function navigate(): CubeNavigation
    {
        $current = $this->navigation;
        if ($current !== null) {
            $current->interrupt();
            if ($current->reflowCount() > self::MAX_REFLOW) {
                // Laço de redirecionamentos: a navegação é abandonada (e esquecida) para que a
                // próxima comece do zero e a sessão continue navegável.
                $this->navigation = null;
                $current->abandon();
                throw new LogicException('Navigation recursion detected');
            }
        }

        return $this->navigation = new CubeNavigation($this, $current);
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /** O presenter ativo de um place, se houver. Útil em testes e para depuração. */
    public function presenterOf(CubePlace $place): ?CubePresenter
    {
        return $this->presenters[$place->id()] ?? null;
    }

    // :: Attributes

    public function setAttribute(string $name, mixed $value): mixed
    {
        $old = $this->attributes[$name] ?? null;
        $this->attributes[$name] = $value;

        return $old;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function removeAttribute(string $name): mixed
    {
        $old = $this->attributes[$name] ?? null;
        unset($this->attributes[$name]);

        return $old;
    }

    // :: Uso interno de CubeNavigation
    //
    // PHP não tem visibilidade de pacote, então são públicos e marcados @internal.

    /**
     * @internal
     * @return array<int, CubePresenter>
     */
    public function activePresenters(): array
    {
        return $this->presenters;
    }

    /**
     * @internal
     * @param array<int, CubePresenter> $presenters
     */
    public function navigationEnded(array $presenters, ?CubePlace $lastPlace): void
    {
        $this->presenters = $presenters;
        if ($lastPlace !== null) {
            $this->lastPlace = $lastPlace;
        }
        $this->navigation = null;
        $this->updateHistory();
    }

    /**
     * Libera em ordem decrescente de id (folha → raiz). Erros são registrados e não interrompem.
     *
     * @internal
     * @param array<int, CubePresenter> $presenters
     */
    public function releaseAll(array $presenters): void
    {
        krsort($presenters);   // ordena pelas chaves, decrescente
        foreach ($presenters as $presenter) {
            try {
                $presenter->release();
            } catch (Throwable $e) {
                $this->logger->error('release de ' . $presenter::class . ': ' . $e->getMessage());
            }
        }
    }
}
