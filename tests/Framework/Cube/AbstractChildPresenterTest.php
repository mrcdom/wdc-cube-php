<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Cube\AbstractChildPresenter;
use Wdc\Framework\Cube\CubeView;
use Wdc\Tests\Framework\Cube\Fixture\FakeView;
use Wdc\Tests\Framework\Cube\Fixture\TestApp;

final class AbstractChildPresenterTest extends TestCase
{
    #[Test]
    public function initialize_createsTheViewBeforeOnInitialize(): void
    {
        // anonymous class: handy for a throwaway test presenter
        $panel = new class (new TestApp()) extends AbstractChildPresenter {
            public ?string $seenView = null;

            protected function onCreateView(): CubeView
            {
                return new FakeView('panel:1');
            }

            protected function onInitialize(): void
            {
                $this->seenView = $this->view?->instanceId();
            }
        };

        $view = $panel->initialize();

        self::assertSame('panel:1', $view->instanceId());
        self::assertSame('panel:1', $panel->seenView, 'onInitialize already sees the view');

        $panel->update();
        $panel->release();
        self::assertInstanceOf(FakeView::class, $view);
        self::assertSame(1, $view->updates);
        self::assertTrue($view->released);
        self::assertNull($panel->view());
    }
}
