<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter;

use Wdc\Framework\Cube\CubeKeys;
use Wdc\Framework\Cube\CubePlace;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * Keys da raiz - a base de todas as keys do Shopping.
 *
 * @extends CubeKeys<ShoppingApplication>
 */
class RootKeys extends CubeKeys
{
    /** Prefixo dos nomes deste arquivo - único na aplicação. */
    private const string PREFIX = 'rt';

    /** Parâmetro: o usuário da sessão (a raiz não aceita trocá-lo pelo endereço). */
    private const string P_USER = self::PREFIX . '-user';

    /**
     * Atributo: o slot de composição - o único nome reusado de propósito. Cada nível do caminho
     * encaixa a sua view no slot que recebeu e o reoferece, com o MESMO nome, ao nível seguinte.
     */
    private const string A_SLOT = self::PREFIX . '-slot';

    /** O id do usuário publicado no endereço. */
    public ?int $userId {
        get => $this->idParameter(self::P_USER);
        set (?int $value) {
            $this->intent->setParameter(self::P_USER, $value);
        }
    }

    /** Onde a view deste nível se encaixa; quem está acima o oferece a cada viagem. */
    public ?CubeViewSlot $ownerSlot {
        get => $this->intent->getViewSlot(self::A_SLOT);
        set (?CubeViewSlot $value) {
            $this->intent->setViewSlot(self::A_SLOT, $value);
        }
    }

    public function place(): CubePlace
    {
        return Place::ROOT;
    }

    public function go(): bool
    {
        $place = $this->place();

        return $place instanceof Place ? $place->go($this->app, $this->intent) : false;
    }
}
