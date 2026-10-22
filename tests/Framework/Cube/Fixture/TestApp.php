<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube\Fixture;

use Closure;
use Wdc\Framework\Commons\Storage\ClientStorage;
use Wdc\Framework\Commons\Storage\InMemoryClientStorage;
use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePlace;

/** App de teste que registra tudo o que o motor faz com os presenters. */
final class TestApp extends CubeApplication
{
    /** @var list<string> */
    public array $log = [];

    public int $historyUpdates = 0;

    /**
     * Comportamento extra por place, executado dentro do applyParameters.
     *
     * @var array<int, Closure(RecordingPresenter, CubeIntent, bool, bool): bool>
     */
    public array $behaviors = [];

    private readonly InMemoryClientStorage $sessionStore;

    private readonly InMemoryClientStorage $persistentStore;

    public function __construct()
    {
        parent::__construct();
        $this->sessionStore = new InMemoryClientStorage();
        $this->persistentStore = new InMemoryClientStorage();
    }

    /** Navega pela cadeia dada; o intent é opcional. */
    public function go(CubeIntent|CubePlace ...$chain): bool
    {
        $intent = new CubeIntent();
        $navigation = $this->navigate();
        foreach ($chain as $item) {
            if ($item instanceof CubeIntent) {
                $intent = $item;
            } else {
                $navigation->step($item);
            }
        }

        return $navigation->execute($intent);
    }

    /** @return list<string> nomes dos places com presenter ativo */
    public function active(): array
    {
        return array_values(array_map(
            static fn (TestPlace $p): string => $p->name(),
            array_filter(TestPlace::cases(), fn (TestPlace $p): bool => $this->presenterOf($p) !== null),
        ));
    }

    public function rootPlace(): CubePlace
    {
        return TestPlace::ROOT;
    }

    public function updateHistory(): void
    {
        $this->historyUpdates++;
    }

    public function clientSessionStore(): ClientStorage
    {
        return $this->sessionStore;
    }

    public function clientPersistentStore(): ClientStorage
    {
        return $this->persistentStore;
    }
}
