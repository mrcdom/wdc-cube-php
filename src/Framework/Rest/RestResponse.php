<?php

declare(strict_types=1);

namespace Wdc\Framework\Rest;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Wdc\Framework\Commons\Serialization\Json;

/** The API's JSON responses. */
final class RestResponse
{
    private function __construct()
    {
    }

    public static function json(mixed $value, int $status = HttpStatus::OK): Response
    {
        return new Response($status, ['content-type' => 'application/json'], Json::encode($value));
    }

    /** `{"error": "..."}`, the error shape used across the API. */
    public static function error(int $status, string $message): Response
    {
        return self::json(['error' => $message], $status);
    }
}
