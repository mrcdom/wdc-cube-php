<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Security;

use DateTimeImmutable;

final readonly class ChallengeResult
{
    public function __construct(
        public string $nonce,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
