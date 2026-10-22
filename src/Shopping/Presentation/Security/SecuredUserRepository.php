<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Security;

use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Domain\User\UserCriteria;
use Wdc\Shopping\Domain\User\UserRepository;

/**
 * @extends SecuredRepository<User, UserCriteria>
 */
final class SecuredUserRepository extends SecuredRepository implements UserRepository
{
}
