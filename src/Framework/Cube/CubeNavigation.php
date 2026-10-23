<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use LogicException;
use Throwable;

/**
 * A navigation is a transaction over the presenter chain:
 *
 * 1. walks the steps from root to leaf; reuses the place's active presenter or creates a new
 *    one, and calls `applyParameters($intent, $initialization, $deepest)`;
 * 2. a presenter that returns false stops the walk (guard/redirect);
 * 3. **commit**: the accepted presenters stay; those that left the chain and those created but
 *    not accepted are released (leaf first);
 * 4. exception ⇒ **rollback**: re-applies the previous intent to the presenters that already
 *    existed and releases the ones just created;
 * 5. if another navigation started midway (redirect), this one was **interrupted**:
 *    it neither commits nor rolls back — the new navigation has already taken care of everything.
 *
 * PHP arrays are values (copied on assignment), so the navigation does not modify the app's
 * presenter map in place: it reads the app state when needed and hands back the final map at once.
 */
final class CubeNavigation
{
    /** @var list<CubePlace> */
    private array $steps = [];

    /** @var array<int, CubePresenter> presenters created in this navigation (or inherited from the interrupted one) */
    private array $created = [];

    private int $reflowCount = 1;

    private bool $interrupted = false;

    /** Previous state, for the rollback. */
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
     * Gives up on the navigation (redirect loop): releases what it created that never
     * became active.
     *
     * @internal
     */
    public function abandon(): void
    {
        $this->app->releaseAll(array_diff_key($this->created, $this->app->activePresenters()));
        $this->created = [];
    }

    /**
     * @return bool true if every presenter in the chain accepted
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
            throw $e;   // PHP has no checked exceptions: rethrow the original, unwrapped
        }
    }

    /**
     * @param array<int, CubePresenter> $accepted
     */
    private function commit(array $accepted, CubePlace $targetPlace): void
    {
        $leaving = array_diff_key($this->app->activePresenters(), $accepted);   // left the chain
        $unused = array_diff_key($this->created, $accepted);                    // created but not accepted
        $this->created = [];
        try {
            // Always release, even with no previously active presenters: on a first navigation
            // with a redirect, the ones created but not accepted must be released too.
            $this->app->releaseAll($leaving + $unused);   // `+` on arrays: union by key
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
                    // log and move on: the original cause is what matters
                    $this->app->logger()->warning('Restoring state after "' . $cause->getMessage() . '": ' . $e->getMessage());
                }
            }
            $this->app->releaseAll($this->created);
            $this->created = [];
        } finally {
            $this->app->navigationEnded($current, null);
        }
    }
}
