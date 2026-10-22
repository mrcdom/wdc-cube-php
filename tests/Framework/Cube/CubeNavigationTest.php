<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wdc\Framework\Cube\CubeIntent;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Tests\Framework\Cube\Fixture\FakeView;
use Wdc\Tests\Framework\Cube\Fixture\RecordingPresenter;
use Wdc\Tests\Framework\Cube\Fixture\TestApp;
use Wdc\Tests\Framework\Cube\Fixture\TestPlace as P;

final class CubeNavigationTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = new TestApp();
    }

    private function presenter(P $place): RecordingPresenter
    {
        $presenter = $this->app->presenterOf($place);
        self::assertInstanceOf(RecordingPresenter::class, $presenter);

        return $presenter;
    }

    /**
     * Devolve o log e o zera — cada asserção olha só o que aconteceu desde a anterior.
     *
     * @return list<string>
     */
    private function takeLog(): array
    {
        $log = $this->app->log;
        $this->app->log = [];

        return $log;
    }

    #[Test]
    public function firstNavigation_createsTheChainFromRootToLeaf(): void
    {
        self::assertTrue($this->app->go(P::ROOT, P::SECTION, P::PAGE));

        self::assertSame([
            'create root', 'apply root init',
            'create section', 'apply section init',
            'create page', 'apply page init deepest',
        ], $this->takeLog());
        self::assertSame(['root', 'section', 'page'], $this->app->active());
        self::assertSame(P::PAGE, $this->app->lastPlace());
        self::assertSame(1, $this->app->historyUpdates);
    }

    #[Test]
    public function sameChain_reusesPresentersWithoutInitialization(): void
    {
        $this->app->go(P::ROOT, P::SECTION, P::PAGE);
        $page = $this->presenter(P::PAGE);
        $this->takeLog();

        $this->app->go(P::ROOT, P::SECTION, P::PAGE);

        self::assertSame(['apply root', 'apply section', 'apply page deepest'], $this->takeLog());
        self::assertSame($page, $this->presenter(P::PAGE), 'mesma instância: o estado sobrevive');
    }

    #[Test]
    public function goingUp_keepsTheParentAndReleasesTheChild(): void
    {
        $this->app->go(P::ROOT, P::SECTION, P::PAGE);
        $this->takeLog();

        $this->app->go(P::ROOT, P::SECTION);

        self::assertSame(['apply root', 'apply section deepest', 'release page'], $this->takeLog());
        self::assertSame(['root', 'section'], $this->app->active());
    }

    #[Test]
    public function changingBranch_releasesLeavingPresentersLeafFirst(): void
    {
        $this->app->go(P::ROOT, P::SECTION, P::PAGE);
        $this->takeLog();

        $this->app->go(P::ROOT, P::OTHER);

        self::assertSame([
            'apply root', 'create other', 'apply other init deepest',
            'release page', 'release section',
        ], $this->takeLog());
        self::assertSame(['root', 'other'], $this->app->active());
    }

    #[Test]
    public function refusal_stopsTheWalkAndCommitsWhatWasAccepted(): void
    {
        $this->app->behaviors[P::SECTION->id()] = static fn (): bool => false;

        self::assertFalse($this->app->go(P::ROOT, P::SECTION, P::PAGE));

        self::assertSame(['create root', 'apply root init', 'create section', 'apply section init'], $this->takeLog());
        self::assertSame(['root', 'section'], $this->app->active(), 'commit parcial do que foi aceito');
    }

    #[Test]
    public function refusal_releasesDeeperPresentersThatWereActive(): void
    {
        $this->app->go(P::ROOT, P::SECTION, P::PAGE);
        $this->app->behaviors[P::SECTION->id()] = static fn (): bool => false;
        $this->takeLog();

        $this->app->go(P::ROOT, P::SECTION, P::PAGE);

        self::assertSame(['apply root', 'apply section', 'release page'], $this->takeLog());
    }

    #[Test]
    public function redirect_interruptsTheOuterNavigationAndTheInnerOneWins(): void
    {
        // a seção é "restrita": manda para o login e recusa
        $app = $this->app;
        $app->behaviors[P::SECTION->id()] = static function () use ($app): bool {
            $app->go(P::ROOT, P::LOGIN);

            return false;
        };

        self::assertFalse($app->go(P::ROOT, P::SECTION, P::PAGE));

        self::assertSame([
            'create root', 'apply root init',
            'create section', 'apply section init',
            // navegação interna: herda o root já criado (sem init)
            'apply root', 'create login', 'apply login init deepest',
            // a seção foi criada pela externa e ficou de fora: liberada
            // (mesmo sem presenters ativos antes da navegação)
            'release section',
        ], $this->takeLog());
        self::assertSame(['root', 'login'], $app->active());
        self::assertSame(P::LOGIN, $app->lastPlace());
        self::assertSame(1, $app->historyUpdates, 'só a navegação interna comitou');
    }

    #[Test]
    public function exception_rollsBackToThePreviousState(): void
    {
        $this->app->go(new CubeIntentBuilder()->with('section', 'antes')->build(), P::ROOT, P::SECTION);
        $this->app->behaviors[P::PAGE->id()] = static fn (): bool => throw new RuntimeException('falhou');
        $this->takeLog();

        try {
            $this->app->go(new CubeIntentBuilder()->with('section', 'depois')->build(), P::ROOT, P::SECTION, P::PAGE);
            self::fail('a exceção deveria ser repropagada');
        } catch (RuntimeException $e) {
            self::assertSame('falhou', $e->getMessage());
        }

        self::assertSame([
            'apply root', 'apply section', 'create page', 'apply page init deepest',
            // rollback: reaplica o intent anterior nos que já existiam e libera o criado
            'apply root', 'apply section deepest',
            'release page',
        ], $this->takeLog());
        self::assertSame(['root', 'section'], $this->app->active());
        self::assertSame('antes', $this->presenter(P::SECTION)->value, 'estado restaurado');
        self::assertSame(P::SECTION, $this->app->lastPlace());
    }

    #[Test]
    public function endlessRedirects_areDetected(): void
    {
        $app = $this->app;
        $app->behaviors[P::ROOT->id()] = static fn (): bool => $app->go(P::ROOT, P::LOGIN);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Navigation recursion detected');
        $app->go(P::ROOT);
    }

    #[Test]
    public function afterEndlessRedirects_theSessionStillNavigates(): void
    {
        $app = $this->app;
        $app->behaviors[P::ROOT->id()] = static fn (): bool => $app->go(P::ROOT, P::LOGIN);
        try {
            $app->go(P::ROOT);
        } catch (LogicException) {
        }
        unset($app->behaviors[P::ROOT->id()]);

        // a navegação interrompida não pode ficar presa na app, senão toda navegação seguinte falharia
        self::assertTrue($app->go(P::ROOT, P::SECTION));
        self::assertSame(['root', 'section'], $app->active());
    }

    #[Test]
    public function newIntent_describesTheCurrentState(): void
    {
        $intent = new CubeIntentBuilder()->with('root', 'r')->with('page', '7')->build();
        $this->app->go($intent, P::ROOT, P::SECTION, P::PAGE);

        self::assertSame('page?root=r&page=7', (string) $this->app->newIntent());
    }

    #[Test]
    public function release_releasesEverythingLeafFirst(): void
    {
        $this->app->go(P::ROOT, P::SECTION, P::PAGE);
        $this->takeLog();

        $this->app->release();

        self::assertSame(['release page', 'release section', 'release root'], $this->takeLog());
        self::assertSame([], $this->app->active());
    }

    #[Test]
    public function slot_letsTheChildPlaceItsViewInTheParent(): void
    {
        $this->app->behaviors[P::ROOT->id()] = static function (RecordingPresenter $root, CubeIntent $intent): bool {
            $intent->setViewSlot('owner', new CubeViewSlot(static function (?CubeView $view) use ($root): void {
                $root->content = $view;
            }));

            return true;
        };
        $this->app->behaviors[P::SECTION->id()] = static function (RecordingPresenter $section, CubeIntent $intent, bool $init): bool {
            if ($init) {
                $section->attachView(new FakeView('section:1'));
            }
            $intent->getViewSlot('owner')?->setView($section->view());

            return true;
        };

        $this->app->go(P::ROOT, P::SECTION);

        self::assertSame('section:1', $this->presenter(P::ROOT)->content?->instanceId());
    }

    #[Test]
    public function presenterRelease_releasesItsView(): void
    {
        $this->app->go(P::ROOT, P::SECTION);
        $view = new FakeView('section:1');
        $this->presenter(P::SECTION)->attachView($view);
        $this->presenter(P::SECTION)->update();

        $this->app->go(P::ROOT);

        self::assertSame(1, $view->updates);
        self::assertTrue($view->released);
    }
}

/** Atalho de teste para montar intents com parâmetros. */
final class CubeIntentBuilder
{
    private CubeIntent $intent;

    public function __construct()
    {
        $this->intent = new CubeIntent();
    }

    public function with(string $name, string $value): self
    {
        $this->intent->setParameter($name, $value);

        return $this;
    }

    public function build(): CubeIntent
    {
        return $this->intent;
    }
}
