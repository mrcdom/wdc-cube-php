<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation;

use PHPUnit\Framework\Attributes\Test;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Shopping\Presentation\Routes;
use Wdc\Tests\Shopping\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\LoginViewMock;

/** Login through the presenters: first access, unrecognized username or password, and access to the system. */
final class ShoppingLoginTest extends PresentationTestCase
{
    #[Test]
    public function loginFirstAccess(): void
    {
        Routes::login($this->app);

        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        self::assertSame(0, $login->state->errorCode, 'User should not have been validated');
    }

    #[Test]
    public function loginFailsWithUnrecognizedUserOrPassword(): void
    {
        Routes::login($this->app);
        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        $login->state->userName = 'admin';
        $login->presenter->onEnter('admin', 'unrecognized password');

        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);   // stays on the login
        self::assertSame(1, $login->state->errorCode, 'User should not have been validated');
        self::assertFalse($login->state->loading);
    }

    #[Test]
    public function loginAccessesTheSystem(): void
    {
        Routes::login($this->app);
        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        $login->state->userName = 'admin';
        $login->presenter->onEnter('admin', 'admin');

        $home = HomeViewMock::cast($this->app->rootView()->state->contentView);
        self::assertSame('John Smith', $home->state->nickName);
        self::assertSame(0, $home->state->cartItemCount);
        self::assertSame(0, $home->state->errorCode, 'User should have been validated');

        $purchases = $home->purchasesPanel();
        $products = $home->productsPanel()->state;
        self::assertSame([], $purchases->state->purchases, 'nothing loaded until the view reports its capacity');

        // the view measures how many items fit (there is no real view here)
        $purchases->presenter->onItemSizeCapacityChanged(3);

        $state = $purchases->state;
        self::assertSame(0, $state->page);
        self::assertSame(3, $state->pageSize);
        self::assertSame(2, $state->totalCount);
        self::assertCount(2, $state->purchases);

        self::assertSame(Seed::ADMIN_SECOND_PURCHASE_ID, $state->purchases[0]->id, 'most recent first');
        self::assertEqualsWithDelta(47.97, $state->purchases[0]->total, 0.001);
        self::assertSame(['Wilson ball', 'Thread seal tape'], $state->purchases[0]->items);
        self::assertSame(Seed::ADMIN_FIRST_PURCHASE_ID, $state->purchases[1]->id);
        self::assertEqualsWithDelta(200.0, $state->purchases[1]->total, 0.001);
        self::assertSame(['Italian design coffee maker'], $state->purchases[1]->items);

        self::assertSame(
            [Seed::COFFEE_MAKER_ID, Seed::WILSON_BALL_ID, Seed::THREAD_SEAL_TAPE_ID, Seed::FLASH_DRIVE2GB_ID],
            array_map(static fn ($p) => $p->id, $products->products),
        );
        foreach ($products->products as $product) {
            self::assertSame('unknown', $product->description, 'the showcase projects without the description');
        }
    }
}
