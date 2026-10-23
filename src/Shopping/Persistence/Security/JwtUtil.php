<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Security;

use DateTimeImmutable;
use JsonException;

/**
 * Hand-rolled HS256 JWT: fixed header, payload {sub, usr, iat, exp},
 * unpadded Base64URL, signature compared in constant time.
 */
final class JwtUtil
{
    private const string HEADER = '{"alg":"HS256","typ":"JWT"}';

    private function __construct()
    {
    }

    public static function create(int $userId, string $userName, int $ttlSeconds, string $secret, DateTimeImmutable $now): string
    {
        $iat = $now->getTimestamp();
        $payload = json_encode(
            ['sub' => $userId, 'usr' => $userName, 'iat' => $iat, 'exp' => $iat + $ttlSeconds],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $signingInput = self::b64(self::HEADER) . '.' . self::b64($payload);

        return $signingInput . '.' . self::sign($signingInput, $secret);
    }

    public static function validate(?string $token, string $secret, DateTimeImmutable $now): ?JwtClaims
    {
        if ($token === null || trim($token) === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        if (!hash_equals(self::sign($parts[0] . '.' . $parts[1], $secret), $parts[2])) {   // constant time
            return null;
        }

        try {
            $payload = json_decode(self::unb64($parts[1]), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($payload) || !is_int($payload['exp'] ?? null) || !is_int($payload['sub'] ?? null)) {
            return null;
        }
        if ($now->getTimestamp() > $payload['exp']) {
            return null;
        }

        return new JwtClaims(
            $payload['sub'],
            is_string($payload['usr'] ?? null) ? $payload['usr'] : null,
            new DateTimeImmutable('@' . $payload['exp']),
        );
    }

    private static function sign(string $data, string $secret): string
    {
        return self::b64(hash_hmac('sha256', $data, $secret, true));
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
