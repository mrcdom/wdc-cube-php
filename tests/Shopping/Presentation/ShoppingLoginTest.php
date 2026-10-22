<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation;

use PHPUnit\Framework\Attributes\Test;
use Wdc\Shopping\Persistence\ShoppingSchema as Seed;
use Wdc\Shopping\Presentation\Routes;
use Wdc\Tests\Shopping\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\LoginViewMock;

/** Login pelos presenters: primeiro acesso, usuário ou senha não reconhecidos e acesso ao sistema. */
final class ShoppingLoginTest extends PresentationTestCase
{
    #[Test]
    public function loginPrimeiroAcesso(): void
    {
        Routes::login($this->app);

        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        self::assertSame(0, $login->state->errorCode, 'Usuário não poderia ter sido validado');
    }

    #[Test]
    public function loginFalhaPorSenhaOuUsuarioNaoReconhecidos(): void
    {
        Routes::login($this->app);
        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        $login->state->userName = 'admin';
        $login->presenter->onEnter('admin', 'senha não reconhecida');

        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);   // continua no login
        self::assertSame(1, $login->state->errorCode, 'Usuário não poderia ter sido validado');
        self::assertFalse($login->state->loading);
    }

    #[Test]
    public function loginAcessoAoSistema(): void
    {
        Routes::login($this->app);
        $login = LoginViewMock::cast($this->app->rootView()->state->contentView);
        $login->state->userName = 'admin';
        $login->presenter->onEnter('admin', 'admin');

        $home = HomeViewMock::cast($this->app->rootView()->state->contentView);
        self::assertSame('João da Silva', $home->state->nickName);
        self::assertSame(0, $home->state->cartItemCount);
        self::assertSame(0, $home->state->errorCode, 'Usuário deveria ter sido validado');

        $purchases = $home->purchasesPanel();
        $products = $home->productsPanel()->state;
        self::assertSame([], $purchases->state->purchases, 'sem carga até a view informar a capacidade');

        // a view mede quantos itens cabem (aqui não há view de verdade)
        $purchases->presenter->onItemSizeCapacityChanged(3);

        $state = $purchases->state;
        self::assertSame(0, $state->page);
        self::assertSame(3, $state->pageSize);
        self::assertSame(2, $state->totalCount);
        self::assertCount(2, $state->purchases);

        self::assertSame(Seed::ADMIN_SECOND_PURCHASE_ID, $state->purchases[0]->id, 'mais recente primeiro');
        self::assertEqualsWithDelta(47.97, $state->purchases[0]->total, 0.001);
        self::assertSame(['Bola Wilson', 'Fita veda rosca'], $state->purchases[0]->items);
        self::assertSame(Seed::ADMIN_FIRST_PURCHASE_ID, $state->purchases[1]->id);
        self::assertEqualsWithDelta(200.0, $state->purchases[1]->total, 0.001);
        self::assertSame(['Cafeteira design italiano'], $state->purchases[1]->items);

        self::assertSame(
            [Seed::CAFETEIRA_ID, Seed::BOLA_WILSON_ID, Seed::FITA_VEDA_ROSCA_ID, Seed::PEN_DRIVE2GB_ID],
            array_map(static fn ($p) => $p->id, $products->products),
        );
        foreach ($products->products as $product) {
            self::assertSame('unknown', $product->description, 'vitrine projeta sem a descrição');
        }
    }
}
