<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Products;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\Presenter\RootKeys;

/** Keys do detalhe do produto: `product?pr-id=3`. */
final class ProductKeys extends RootKeys
{
    /** Prefixo dos nomes deste arquivo - único na aplicação. */
    private const string PREFIX = 'pr';

    /** Parâmetro: o produto mostrado. */
    private const string P_ID = self::PREFIX . '-id';

    /** O produto: inteiro não negativo, ou null se o endereço não traz um utilizável. */
    public ?int $productId {
        get => $this->idParameter(self::P_ID);
        set (?int $value) {
            $this->intent->setParameter(self::P_ID, $value);
        }
    }

    public function place(): CubePlace
    {
        return Place::PRODUCT;
    }
}
