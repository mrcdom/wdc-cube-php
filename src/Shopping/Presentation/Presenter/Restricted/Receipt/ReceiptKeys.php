<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Restricted\Receipt;

use Wdc\Framework\Cube\CubePlace;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\Presenter\Restricted\Home\HomeKeys;

/** Receipt keys: `receipt?rc-id=7`. */
final class ReceiptKeys extends HomeKeys
{
    /** Prefix of this file's names - unique in the application. */
    private const string PREFIX = 'rc';

    /** Parameter: the receipt's purchase. */
    private const string P_ID = self::PREFIX . '-id';

    /** Attribute: "the purchase was just made" - the receipt shows the success notice. */
    private const string A_MADE = self::PREFIX . '-made';

    /** The purchase: a non-negative integer, or null if the address does not carry a usable one. */
    public ?int $purchaseId {
        get => $this->idParameter(self::P_ID);
        set (?int $value) {
            $this->intent->setParameter(self::P_ID, $value);
        }
    }

    /** Whether navigation comes from a just-completed purchase (not put in the address). */
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
