<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter;

use Wdc\Framework\Cube\CubeKeys;
use Wdc\Framework\Cube\CubePlace;
use Wdc\Framework\Cube\CubeViewSlot;
use Wdc\Shopping\Presentation\Place;
use Wdc\Shopping\Presentation\ShoppingApplication;

/**
 * Root keys - the base of every Shopping keys class.
 *
 * @extends CubeKeys<ShoppingApplication>
 */
class RootKeys extends CubeKeys
{
    /** Prefix of this file's names - unique in the application. */
    private const string PREFIX = 'rt';

    /** Parameter: the session's user (the root does not accept switching it through the address). */
    private const string P_USER = self::PREFIX . '-user';

    /**
     * Attribute: the composition slot - the only name reused on purpose. Each level of the path
     * plugs its view into the slot it received and re-offers it, under the SAME name, to the next level.
     */
    private const string A_SLOT = self::PREFIX . '-slot';

    /** The user id published in the address. */
    public ?int $userId {
        get => $this->idParameter(self::P_USER);
        set (?int $value) {
            $this->intent->setParameter(self::P_USER, $value);
        }
    }

    /** Where this level's view plugs in; the level above offers it on every trip. */
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
