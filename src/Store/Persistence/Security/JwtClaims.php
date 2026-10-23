<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence\Security;

use DateTimeImmutable;

final readonly class JwtClaims
{
    public function __construct(
        public int $userId,
        public ?string $userName,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
