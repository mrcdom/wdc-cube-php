<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\User;

/**
 * Provisioned orderings for User.
 * PHP has no nested types, so the enum lives next to UserCriteria.
 */
enum UserOrderBy
{
    case OLDEST_FIRST;
    case NEWEST_FIRST;
    case NAME_A_TO_Z;
    case LOGIN_A_TO_Z;
}
