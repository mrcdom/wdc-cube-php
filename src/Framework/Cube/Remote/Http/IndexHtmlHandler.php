<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote\Http;

use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;

use function Amp\File\read;

/**
 * Serve o `index.html` de um shell e planta os cookies da sessão (o IndexHtmlController):
 * - `app_id`: id novo, assinado pelo servidor, válido por 10 s (o shell o lê e apaga);
 * - `app_skey`: a chave pública do handshake ("e:n" em base 36).
 *
 * Os dois precisam ser legíveis por JavaScript (sem HttpOnly). `Secure` só em HTTPS (direto ou
 * via proxy): em HTTP puro o browser recusaria o cookie e o shell entraria em loop de reload.
 */
final readonly class IndexHtmlHandler implements RequestHandler
{
    /**
     * O nome de um frontend (a pasta em `work/frontend/` e o primeiro segmento da URL). Começa
     * por letra ou dígito: como vira parte de um caminho de arquivo, `..` nunca pode casar.
     */
    public const string CONTEXT_PATTERN = '[A-Za-z0-9][A-Za-z0-9._-]*';

    public function __construct(
        private RemoteAppSecurity $security,
        private string $frontendDir,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        $args = $request->getAttribute(Router::class);
        $context = is_array($args) && is_string($args['ctx'] ?? null) ? $args['ctx'] : '';
        $file = "{$this->frontendDir}/{$context}/index.html";
        if (preg_match('#^' . self::CONTEXT_PATTERN . '$#', $context) !== 1 || !is_file($file)) {
            return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'text/plain'], 'Not found');
        }

        $response = new Response(HttpStatus::OK, [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'no-cache, no-store',
            'pragma' => 'no-cache',
            'expires' => '0',
        ], read($file));   // Amp\File\read: lê sem travar o event loop

        $attributes = CookieAttributes::empty()->withPath('/');
        if (self::isHttps($request)) {
            $attributes = $attributes->withSecure();
        }
        $response->setCookie(new ResponseCookie('app_id', $this->security->newAppId(), $attributes->withMaxAge(10)));
        $response->setCookie(new ResponseCookie('app_skey', $this->security->webKey(), $attributes));

        return $response;
    }

    private static function isHttps(Request $request): bool
    {
        $forwarded = $request->getHeader('x-forwarded-proto');
        if ($forwarded !== null && trim($forwarded) !== '') {
            return strcasecmp(trim($forwarded), 'https') === 0;
        }

        return $request->getUri()->getScheme() === 'https';
    }
}
