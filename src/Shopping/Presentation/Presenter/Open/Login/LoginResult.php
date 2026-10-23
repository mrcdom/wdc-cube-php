<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

final readonly class LoginResult
{
    public function __construct(
        public Subject $subject,
        public ?string $persistentToken,
    ) {
    }
}
