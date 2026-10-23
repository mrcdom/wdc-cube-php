<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube\Fixture;

use Closure;
use Wdc\Framework\Commons\Storage\ClientStorage;
use Wdc\Framework\Commons\Storage\InMemoryClientStorage;
use Wdc\Framework\Cube\CubeApplication;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubePlace;

/** Test app that records everything the engine does with the presenters. */
final class TestApp extends CubeApplication
{
    /** @var list<string> */
    public array $log = [];

    public int $historyUpdates = 0;

    /**
     * Extra behaviour per place, run inside applyParameters.
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

    /** Navigates through the given chain; the intent is optional. */
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

    /** @return list<string> names of the places with an active presenter */
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
