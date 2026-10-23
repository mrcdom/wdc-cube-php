<?php

declare(strict_types=1);

namespace Wdc\Framework\Rest;

use Amp\Http\Server\Request;
use Amp\Http\Server\Router;
use Wdc\Framework\Commons\Serialization\Json;

/**
 * A REST request with its body already read (only what the API uses).
 *
 * The body is read **before** calling the handler: reading is the request's only wait (await).
 * From then on everything is synchronous — which lets us set the security context and bind a
 * remote transaction's connection without another Fiber running in between.
 */
final class RestRequest
{
    private mixed $json = null;

    private bool $decoded = false;

    /**
     * @param array<string, string> $headers    lowercase names
     * @param array<string, string> $pathParams
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers,
        private readonly array $pathParams,
        public readonly string $body,
    ) {
    }

    public static function from(Request $request, int $bodyLimit): self
    {
        $request->getBody()->increaseSizeLimit($bodyLimit);
        $body = $request->getBody()->buffer();   // the only wait: synchronous from here on
        $args = $request->getAttribute(Router::class);
        $params = [];
        if (is_array($args)) {
            foreach ($args as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $params[$name] = $value;
                }
            }
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        return new self($request->getMethod(), $request->getUri()->getPath(), $headers, $params, $body);
    }

    /**
     * For tests and for building requests by hand.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $pathParams
     */
    public static function of(string $method, string $path, string $body = '', array $headers = [], array $pathParams = []): self
    {
        return new self($method, $path, array_change_key_case($headers, CASE_LOWER), $pathParams, $body);
    }

    /** Header (case-insensitive name); null if absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function pathParam(string $name): ?string
    {
        return $this->pathParams[$name] ?? null;
    }

    /** The decoded JSON body (objects as stdClass); empty body = `{}`. */
    public function json(): mixed
    {
        if (!$this->decoded) {
            $this->json = Json::decode($this->body);
            $this->decoded = true;
        }

        return $this->json;
    }
}
