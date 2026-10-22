<?php

declare(strict_types=1);

namespace Wdc\Framework\Rest;

use Amp\Http\Server\Request;
use Amp\Http\Server\Router;
use Wdc\Framework\Commons\Serialization\Json;

/**
 * Uma requisição REST com o corpo já lido (só o que a API usa).
 *
 * O corpo é lido **antes** de chamar o handler: ler é a única espera (await) da requisição.
 * Daí em diante tudo é síncrono — o que permite pôr o contexto de segurança e ligar a conexão
 * de uma transação remota sem que outra Fiber rode no meio.
 */
final class RestRequest
{
    private mixed $json = null;

    private bool $decoded = false;

    /**
     * @param array<string, string> $headers    nomes em minúsculas
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
        $body = $request->getBody()->buffer();   // a única espera: daqui em diante, síncrono
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
     * Para testes e para montar requisições à mão.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $pathParams
     */
    public static function of(string $method, string $path, string $body = '', array $headers = [], array $pathParams = []): self
    {
        return new self($method, $path, array_change_key_case($headers, CASE_LOWER), $pathParams, $body);
    }

    /** Cabeçalho (nome sem diferença de maiúsculas); null se ausente. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function pathParam(string $name): ?string
    {
        return $this->pathParams[$name] ?? null;
    }

    /** O corpo JSON decodificado (objetos como stdClass); corpo vazio = `{}`. */
    public function json(): mixed
    {
        if (!$this->decoded) {
            $this->json = Json::decode($this->body);
            $this->decoded = true;
        }

        return $this->json;
    }
}
