<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence\Rest\Security;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\SecurityContext;
use Wdc\Framework\Rest\RestFilter;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;

/**
 * Filter for the protected endpoints (`/api/repo/*`, `/api/tx/*`): requires `Authorization: Bearer
 * <jwt>`, validates it through the AuthenticationService and provides the request's security context.
 *
 * The CORS preflight (OPTIONS) and the product image (public catalog) are left out.
 */
final readonly class SecurityFilter implements RestFilter
{
    private const string BEARER_PREFIX = 'Bearer ';

    public function __construct(private AuthenticationService $auth)
    {
    }

    public function filter(RestRequest $request): Response|SecurityContext|null
    {
        if (strtoupper($request->method) === 'OPTIONS' || str_ends_with($request->path, '/image')) {
            return null;
        }
        $header = $request->header('Authorization');
        if ($header === null || !str_starts_with($header, self::BEARER_PREFIX)) {
            return RestResponse::error(HttpStatus::UNAUTHORIZED, 'Missing or invalid Authorization header');
        }
        $context = $this->auth->resolveToken(substr($header, strlen(self::BEARER_PREFIX)));

        return $context ?? RestResponse::error(HttpStatus::UNAUTHORIZED, 'Invalid or expired token');
    }
}
