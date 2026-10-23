<?php

declare(strict_types=1);

namespace Wdc\Framework\Rest;

use Amp\Http\Server\Response;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * Runs before the handler. Returns a response to short-circuit the
 * request, or the security context the handler should run with (null = none).
 */
interface RestFilter
{
    public function filter(RestRequest $request): Response|SecurityContext|null;
}
