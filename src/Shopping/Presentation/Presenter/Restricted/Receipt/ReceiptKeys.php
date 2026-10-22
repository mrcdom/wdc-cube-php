<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\Presenter\RootKeys;

/** Keys do recibo: `receipt?rc-id=7`. */
final class ReceiptKeys extends RootKeys
{
    /** Prefixo dos nomes deste arquivo - único na aplicação. */
    private const string PREFIX = 'rc';

    /** Parâmetro: a compra do recibo. */
    private const string P_ID = self::PREFIX . '-id';

    /** Atributo: "a compra acabou de ser feita" - o recibo mostra o aviso de sucesso. */
    private const string A_MADE = self::PREFIX . '-made';

    /** A compra: inteiro não negativo, ou null se o endereço não traz uma utilizável. */
    public ?int $purchaseId {
        get => $this->idParameter(self::P_ID);
        set (?int $value) {
            $this->intent->setParameter(self::P_ID, $value);
        }
    }

    /** Se a navegação vem de uma compra recém-concluída (não vai para o endereço). */
    public bool $purchaseMade {
        get => $this->intent->getAttribute(self::A_MADE) === true;
        set (bool $value) {
            $this->intent->setAttribute(self::A_MADE, $value);
        }
    }

    public function place(): CubePlace
    {
        return Place::RECEIPT;
    }
}
