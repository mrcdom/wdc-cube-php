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
 * Serves a shell's `index.html` and sets the session cookies:
 * - `app_id`: a new id, signed by the server, valid for 10 s (the shell reads and deletes it);
 * - `app_skey`: the handshake public key ("e:n" in base 36).
 *
 * Both must be readable by JavaScript (no HttpOnly). `Secure` only on HTTPS (direct or
 * via proxy): on plain HTTP the browser would reject the cookie and the shell would loop reloading.
 */
final readonly class IndexHtmlHandler implements RequestHandler
{
    /**
     * The name of a frontend (the folder in `work/frontend/` and the first URL segment). It starts
     * with a letter or digit: since it becomes part of a file path, `..` must never match.
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
        ], read($file));   // Amp\File\read: reads without blocking the event loop

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
