<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Shopping\Domain\User\User;

/** The logged-in user, from the presentation's point of view. */
final readonly class Subject
{
    public function __construct(
        public int $id,
        public ?string $nickName,
    ) {
    }

    public static function projection(): User
    {
        return new User()->withId(PV::INT)->withName(PV::STR);
    }

    public static function create(?User $user): ?self
    {
        $id = $user?->id();

        return $user === null || $id === null ? null : new self($id, $user->name());
    }
}
