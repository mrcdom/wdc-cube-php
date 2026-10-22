<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use LogicException;
use Throwable;

/**
 * Uma navegação é uma transação sobre a cadeia de presenters:
 *
 * 1. percorre os steps da raiz para a folha; reaproveita o presenter ativo do place ou
 *    cria um novo, e chama `applyParameters($intent, $initialization, $deepest)`;
 * 2. um presenter que devolve false para a caminhada (guarda/redirecionamento);
 * 3. **commit**: ficam os presenters aceitos; os que saíram da cadeia e os criados e não
 *    aceitos são liberados (folha primeiro);
 * 4. exceção ⇒ **rollback**: reaplica o intent anterior nos presenters que já existiam e
 *    libera os criados agora;
 * 5. se outra navegação começou no meio (redirecionamento), esta foi **interrompida**:
 *    não comita nem desfaz — a navegação nova já cuidou de tudo.
 *
 * Arrays PHP são valores (copiados na atribuição), então a navegação não modifica o mapa
 * de presenters da app no lugar: lê o estado da app quando precisamos e devolvemos o mapa final de uma vez.
 */
final class CubeNavigation
{
    /** @var list<CubePlace> */
    private array $steps = [];

    /** @var array<int, CubePresenter> presenters criados nesta navegação (ou herdados da interrompida) */
    private array $created = [];

    private int $reflowCount = 1;

    private bool $interrupted = false;

    /** Estado anterior, para o rollback. */
    private readonly CubeIntent $sourceIntent;

    public function __construct(private readonly CubeApplication $app, ?self $interrupted = null)
    {
        if ($interrupted !== null) {
            $this->reflowCount = $interrupted->reflowCount + 1;
            $this->created = $interrupted->created;
        }
        $this->sourceIntent = $app->newIntent();
    }

    public function step(CubePlace $place): self
    {
        $this->steps[] = $place;

        return $this;
    }

    public function reflowCount(): int
    {
        return $this->reflowCount;
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
    }

    /**
     * Desiste da navegação (laço de redirecionamentos): libera o que ela criou e que não
     * chegou a ficar ativo.
     *
     * @internal
     */
    public function abandon(): void
    {
        $this->app->releaseAll(array_diff_key($this->created, $this->app->activePresenters()));
        $this->created = [];
    }

    /**
     * @return bool true se todos os presenters da cadeia aceitaram
     */
    public function execute(CubeIntent $target): bool
    {
        if ($this->steps === []) {
            throw new LogicException('Navigation without steps');
        }
        $targetPlace = $this->steps[array_key_last($this->steps)];

        try {
            $result = true;
            $accepted = [];
            $last = count($this->steps) - 1;
            foreach ($this->steps as $i => $place) {
                $deepest = $i === $last;
                $target->setPlace($place);
                $id = $place->id();

                $presenter = $this->app->activePresenters()[$id] ?? $this->created[$id] ?? null;
                $initialization = $presenter === null;
                if ($presenter === null) {
                    $presenter = $place->createPresenter($this->app);
                    $this->created[$id] = $presenter;
                }
                $accepted[$id] = $presenter;

                $goAhead = $presenter->applyParameters($target, $initialization, $deepest);
                if (!$goAhead || $this->interrupted) {
                    $result = false;
                    break;
                }
            }

            if (!$this->interrupted) {
                $this->commit($accepted, $targetPlace);
            }

            return $result;
        } catch (Throwable $e) {
            if (!$this->interrupted) {
                $this->rollback($e);
            }
            throw $e;   // PHP não tem checked exceptions: relança a original, sem embrulhar
        }
    }

    /**
     * @param array<int, CubePresenter> $accepted
     */
    private function commit(array $accepted, CubePlace $targetPlace): void
    {
        $leaving = array_diff_key($this->app->activePresenters(), $accepted);   // saíram da cadeia
        $unused = array_diff_key($this->created, $accepted);                    // criados e não aceitos
        $this->created = [];
        try {
            // Libera sempre, mesmo sem presenters ativos antes: numa primeira navegação com
            // redirecionamento, os criados e não aceitos também precisam ser liberados.
            $this->app->releaseAll($leaving + $unused);   // `+` em arrays: união por chave
        } finally {
            $this->app->navigationEnded($accepted, $targetPlace);
        }
    }

    private function rollback(Throwable $cause): void
    {
        $current = $this->app->activePresenters();
        try {
            ksort($current);
            $last = array_key_last($current);
            foreach ($current as $id => $presenter) {
                unset($this->created[$id]);
                try {
                    $presenter->applyParameters($this->sourceIntent, false, $id === $last);
                } catch (Throwable $e) {
                    // registrar e seguir: a causa original é a que importa
                    $this->app->logger()->warning('Restaurando estado após "' . $cause->getMessage() . '": ' . $e->getMessage());
                }
            }
            $this->app->releaseAll($this->created);
            $this->created = [];
        } finally {
            $this->app->navigationEnded($current, null);
        }
    }
}
