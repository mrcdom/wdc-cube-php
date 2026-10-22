<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

use Wdc\Framework\Domain\Security\SecurityContext;

final readonly class LoginResult
{
    public function __construct(
        public Subject $subject,
        public ?SecurityContext $securityContext,
        public ?string $persistentToken,
    ) {
    }
}
