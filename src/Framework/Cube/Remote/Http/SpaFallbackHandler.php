<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * O que não foi rota nem arquivo estático: `/<shell>/qualquer-coisa` volta para o `index.html`
 * daquele shell (a navegação interna é pelo hash, então o servidor não conhece as telas).
 */
final readonly class SpaFallbackHandler implements RequestHandler
{
    private const string STATIC = '/\.(js|mjs|wasm|css|html|json|map|png|jpg|gif|svg|woff2?|ttf|otf)$/';

    public function __construct(private string $frontendDir)
    {
    }

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        if (preg_match('#^/(' . IndexHtmlHandler::CONTEXT_PATTERN . ')(/.*)?$#', $path, $m) === 1
            && is_file("{$this->frontendDir}/{$m[1]}/index.html")
            && preg_match(self::STATIC, $path) !== 1
            && !str_contains($path, '/api/')) {
            return new Response(HttpStatus::FOUND, ['location' => "/{$m[1]}/index.html"]);
        }

        return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'text/plain'], 'Not found');
    }
}
