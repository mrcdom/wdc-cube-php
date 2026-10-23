<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Whatever was neither a route nor a static file: `/<shell>/anything` goes back to that shell's
 * `index.html` (internal navigation uses the hash, so the server does not know the screens).
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
