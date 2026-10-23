<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Wdc\Framework\Commons\Storage\ClientStorage;

/**
 * Root of a session: holds the active presenters (one per place in the current chain),
 * the session attributes, and the last place navigated to.
 *
 * Threading: everything runs on a single event loop — nothing runs in parallel, so plain arrays
 * are enough and there are no locks. A Fiber only yields at explicit wait points (I/O, delay).
 */
abstract class CubeApplication
{
    /** Beyond this, nested redirects are treated as a loop. */
    public const int MAX_REFLOW = 10;

    /** @var array<int, CubePresenter> place id → presenter */
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

    /** Called at the end of every navigation (commit or rollback): this is where the URL is rewritten. */
    abstract public function updateHistory(): void;

    /** Session storage (lives as long as the app lives). */
    abstract public function clientSessionStore(): ClientStorage;

    /** Persistent storage; `secure()` for the encrypted variant. */
    abstract public function clientPersistentStore(): ClientStorage;

    /** Releases all presenters, from leaf to root. */
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
                $this->logger->error('commitComputedState in ' . $presenter::class . ': ' . $e->getMessage());
            }
        }
    }

    /** Intent describing the current state: last place + published parameters. */
    public function newIntent(): CubeIntent
    {
        $intent = new CubeIntent();
        $intent->setPlace($this->lastPlace);
        $this->publishParameters($intent);

        return $intent;
    }

    /**
     * Starts a navigation: `$app->navigate()->step(A)->step(B)->execute($intent)`.
     *
     * If another navigation is in progress (a presenter redirected from inside its
     * applyParameters), it is interrupted and the new one inherits the presenters it already created.
     */
    public function navigate(): CubeNavigation
    {
        $current = $this->navigation;
        if ($current !== null) {
            $current->interrupt();
            if ($current->reflowCount() > self::MAX_REFLOW) {
                // Redirect loop: the navigation is abandoned (and forgotten) so that the next
                // one starts from scratch and the session stays navigable.
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

    /** The active presenter of a place, if any. Useful in tests and for debugging. */
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

    // :: Internal use by CubeNavigation
    //
    // PHP has no package visibility, so these are public and marked @internal.

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
     * Releases in descending id order (leaf → root). Errors are logged and do not interrupt.
     *
     * @internal
     * @param array<int, CubePresenter> $presenters
     */
    public function releaseAll(array $presenters): void
    {
        krsort($presenters);   // sorts by key, descending
        foreach ($presenters as $presenter) {
            try {
                $presenter->release();
            } catch (Throwable $e) {
                $this->logger->error('release of ' . $presenter::class . ': ' . $e->getMessage());
            }
        }
    }
}
