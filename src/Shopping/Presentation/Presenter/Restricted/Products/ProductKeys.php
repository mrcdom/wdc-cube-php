<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Products;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomeKeys;

/** Product detail keys: `product?pr-id=3`. */
final class ProductKeys extends HomeKeys
{
    /** Prefix of this file's names - unique in the application. */
    private const string PREFIX = 'pr';

    /** Parameter: the product shown. */
    private const string P_ID = self::PREFIX . '-id';

    /** The product: a non-negative integer, or null if the address does not carry a usable one. */
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
