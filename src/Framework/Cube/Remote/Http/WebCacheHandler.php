<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function Amp\File\createDirectoryRecursively;
use function Amp\File\exists;
use function Amp\File\read;
use function Amp\File\write;

/**
 * `/web-cache/<host>/<caminho>`: baixa uma vez de `https://<host>/<caminho>`, guarda em disco e
 * serve dali (o WebCacheController). O shell carrega as fontes e os ícones por aqui. Em CSS e
 * JS, as URLs `https://` são reescritas para passarem também pelo cache.
 */
final readonly class WebCacheHandler implements RequestHandler
{
    private const string PREFIX = '/web-cache/';

    private const array CONTENT_TYPES = [
        'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'html' => 'text/html; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif',
        'ico' => 'image/x-icon', 'map' => 'application/json',
    ];

    private HttpClient $http;

    public function __construct(
        private string $cacheDir,
        private LoggerInterface $logger,
        ?HttpClient $http = null,
    ) {
        $this->http = $http ?? HttpClientBuilder::buildDefault();
    }

    public function handleRequest(Request $request): Response
    {
        $uri = $request->getUri();
        $urlPath = substr(rawurldecode($uri->getPath()), strlen(self::PREFIX));
        if ($urlPath === '') {
            return new Response(HttpStatus::BAD_REQUEST, ['content-type' => 'text/plain'], 'Missing URL');
        }
        $query = $uri->getQuery();
        $source = 'https://' . $urlPath . ($query !== '' ? "?{$query}" : '');

        try {
            $local = $this->cachePath($urlPath, $query);
            if (!exists($local)) {
                $this->download($source, $local);
            }
            $contentType = exists("{$local}.type") ? trim(read("{$local}.type")) : self::contentTypeOf($local);
            $content = read($local);
            if (str_starts_with($contentType, 'text/css') || str_starts_with($contentType, 'application/javascript')) {
                // sub-recursos (as fontes citadas no CSS) também passam pelo cache
                $content = str_replace('https://', self::PREFIX, $content);

                return new Response(HttpStatus::OK, ['content-type' => $contentType, 'cache-control' => 'public, max-age=86400'], $content);
            }

            return new Response(HttpStatus::OK, ['content-type' => $contentType, 'cache-control' => 'public, max-age=31536000, immutable'], $content);
        } catch (Throwable $e) {
            $this->logger->error("Web cache error for: {$source}", ['exception' => $e]);

            return new Response(HttpStatus::BAD_GATEWAY, ['content-type' => 'text/plain'], 'Failed to fetch resource');
        }
    }

    private function download(string $source, string $local): void
    {
        $this->logger->info("Downloading and caching: {$source}");
        $request = new ClientRequest($source);
        $request->setHeader('user-agent', 'Mozilla/5.0 (compatible; WDC-WebCache/1.0)');
        $request->setTransferTimeout(30);
        $response = $this->http->request($request);   // não trava o loop: a Fiber espera a resposta
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new RuntimeException("HTTP {$response->getStatus()} fetching {$source}");
        }
        createDirectoryRecursively(dirname($local));
        write($local, $response->getBody()->buffer());
        // Não dá para deduzir o tipo pela extensão: `css2?family=...` (Google Fonts) não tem
        // extensão, vira `_index.js` e sairia como JavaScript — e o browser se recusaria a aplicar
        // a folha de estilo. Guardamos o tipo que a origem informou.
        $type = $response->getHeader('content-type');
        if ($type !== null && $type !== '') {
            write("{$local}.type", $type);
        }
    }

    /** Caminho no disco: host + caminho; sem extensão conhecida ⇒ `_index.js`; query ⇒ hash no nome. */
    private function cachePath(string $urlPath, string $query): string
    {
        $sanitized = (string) preg_replace('/[<>:"|?*]/', '_', str_replace('..', '', $urlPath));
        $path = rtrim($this->cacheDir, '/') . '/' . ltrim($sanitized, '/');
        if (!isset(self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            $path .= '/_index.js';
        }
        if ($query !== '') {
            $info = pathinfo($path);
            $hash = hash('crc32b', $query);
            $path = $info['dirname'] . '/' . $info['filename'] . '_' . $hash . (isset($info['extension']) ? '.' . $info['extension'] : '');
        }

        return $path;
    }

    private static function contentTypeOf(string $file): string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
