<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence\Rest;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Closure;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\IsoDateTime;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\AuthResult;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;

/**
 * `/api/auth/*` — public: login is an HMAC challenge (the password never travels).
 *
 * 1. `GET challenge` → `{nonce, expiresAt}`
 * 2. `POST login` `{userName, digest, nonce}` → tokens + the session's RSA public key
 * 3. `POST refresh` `{refreshToken}` → new tokens
 * 4. `POST logout` `{refreshToken}` → ends the session
 */
final readonly class AuthApiController
{
    public function __construct(private AuthenticationService $auth)
    {
    }

    /** @param Closure(string, string, Closure(RestRequest): Response): void $add */
    public function routes(string $prefix, Closure $add): void
    {
        $add('GET', "{$prefix}/api/auth/challenge", $this->challenge(...));
        $add('POST', "{$prefix}/api/auth/login", $this->login(...));
        $add('POST', "{$prefix}/api/auth/refresh", $this->refresh(...));
        $add('POST', "{$prefix}/api/auth/logout", $this->logout(...));
    }

    private function challenge(RestRequest $request): Response
    {
        $result = $this->auth->challenge();

        return RestResponse::json(['nonce' => $result->nonce, 'expiresAt' => IsoDateTime::formatInstant($result->expiresAt)]);
    }

    private function login(RestRequest $request): Response
    {
        $fields = Json::fields($request->json());
        $userName = InputCoerce::asString($fields['userName'] ?? null);
        $digest = InputCoerce::asString($fields['digest'] ?? null);
        $nonce = InputCoerce::asString($fields['nonce'] ?? null);
        if ($userName === null || $digest === null || $nonce === null
            || trim($userName) === '' || trim($digest) === '' || trim($nonce) === '') {
            return RestResponse::error(HttpStatus::BAD_REQUEST, 'Missing required fields: userName, digest, nonce');
        }
        $result = $this->auth->login($userName, $digest, $nonce);

        return $result === null
            ? RestResponse::error(HttpStatus::UNAUTHORIZED, 'Invalid credentials')
            : self::tokens($result);
    }

    private function refresh(RestRequest $request): Response
    {
        $refreshToken = InputCoerce::asString(Json::fields($request->json())['refreshToken'] ?? null);
        if ($refreshToken === null) {
            return RestResponse::error(HttpStatus::BAD_REQUEST, 'Missing refreshToken');
        }
        $result = $this->auth->refresh($refreshToken);

        return $result === null
            ? RestResponse::error(HttpStatus::UNAUTHORIZED, 'Invalid or expired refresh token')
            : self::tokens($result);
    }

    private function logout(RestRequest $request): Response
    {
        $this->auth->logout(InputCoerce::asString(Json::fields($request->json())['refreshToken'] ?? null));

        return RestResponse::json(['success' => true]);
    }

    private static function tokens(AuthResult $result): Response
    {
        return RestResponse::json([
            'userId' => $result->userId,
            'accessToken' => $result->accessToken,
            'refreshToken' => $result->refreshToken,
            'expiresAt' => IsoDateTime::formatInstant($result->expiresAt),
            'publicKey' => $result->publicKey(),
        ]);
    }
}
