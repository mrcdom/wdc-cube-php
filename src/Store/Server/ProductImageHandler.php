<?php

declare(strict_types=1);

namespace Wdc\Store\Server;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Exception\BusinessException;
use Wdc\Store\Domain\Product\ProductRepository;

/** `GET /image/product/{id}.png` — the only HTTP data call the React shell makes. */
final readonly class ProductImageHandler implements RequestHandler
{
    private ProductRepository $products;

    public function __construct(private LoggerInterface $logger)
    {
        $this->products = Beans::of(ProductRepository::class);
    }

    public function handleRequest(Request $request): Response
    {
        $args = $request->getAttribute(Router::class);
        $id = is_array($args) ? filter_var($args['id'] ?? null, FILTER_VALIDATE_INT) : false;
        if ($id === false) {
            return new Response(HttpStatus::BAD_REQUEST);
        }
        try {
            $image = $this->products->fetchImage($id);
        } catch (BusinessException) {
            return new Response(HttpStatus::NOT_FOUND);   // no such product
        } catch (Throwable $e) {
            $this->logger->error('Processing image request', ['exception' => $e]);

            return new Response(HttpStatus::INTERNAL_SERVER_ERROR);
        }

        return $image === null
            ? new Response(HttpStatus::NO_CONTENT)
            : new Response(HttpStatus::OK, ['content-type' => 'image/png', 'cache-control' => 'public, max-age=3600'], $image);
    }
}
