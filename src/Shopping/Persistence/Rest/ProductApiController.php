<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Exception\BusinessException;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCodec;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Domain\Product\ProductRepository;
use Wdc\Shopping\Persistence\Rest\Security\SecurityEnforcer;

/** `/api/repo/product/*` — catalog. No per-user scoping; the image (GET) is public. */
final class ProductApiController extends ApiController
{
    private readonly ProductCodec $codec;

    private readonly ProductRepository $repo;

    public function __construct(
        SecurityEnforcer $security,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($security);
        $this->repo = Beans::of(ProductRepository::class);
        $this->codec = new ProductCodec();
    }

    public function routes(string $prefix, Closure $add): void
    {
        $base = $prefix . '/api/repo/product';
        $add('POST', "{$base}/insert", $this->insert(...), true);
        $add('POST', "{$base}/update", $this->update(...), true);
        $add('POST', "{$base}/delete", $this->delete(...), true);
        $add('POST', "{$base}/count", $this->count(...), false);
        $add('POST', "{$base}/fetch", $this->fetch(...), false);
        $add('POST', "{$base}/fetch-page", $this->fetchPage(...), false);
        $add('POST', "{$base}/fetch-by-id", $this->fetchByIdPost(...), false);
        $add('GET', "{$base}/{id:\\d+}", $this->fetchById(...), false);
        $add('GET', "{$base}/{id:\\d+}/image", $this->fetchImage(...), false);
        $add('PUT', "{$base}/{id:\\d+}/image", $this->updateImage(...), true);
    }

    /** Default projection: everything but the image. */
    public static function fullProjection(): Product
    {
        return (new Product())->withId(PV::INT)->withName(PV::STR)->withPrice(PV::FLOAT)->withDescription(PV::STR);
    }

    private function insert(RestRequest $request): Response
    {
        $this->security->require('product', 'write');
        $product = $this->codec->readEntity($request->json());
        $success = $this->repo->insert($product);

        return self::insertResult($success, $product->id());
    }

    private function update(RestRequest $request): Response
    {
        $this->security->require('product', 'write');
        $data = $this->codec->readEntityForUpdate($request->json());

        return RestResponse::json(['success' => $this->repo->update($data->entity, null, $data->projection)]);
    }

    private function delete(RestRequest $request): Response
    {
        $this->security->require('product', 'write');   // deleting a product requires write (there is no product:delete)
        $criteria = self::readCriteria($request->json(), $this->codec, new ProductCriteria());

        return RestResponse::json(['count' => $this->repo->delete($criteria)]);
    }

    private function count(RestRequest $request): Response
    {
        $this->security->require('product', 'read');
        $criteria = self::readCriteria($request->json(), $this->codec, new ProductCriteria());

        return RestResponse::json(['count' => $this->repo->count($criteria)]);
    }

    private function fetch(RestRequest $request): Response
    {
        $this->security->require('product', 'read');
        $criteria = new ProductCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['offset', 'limit']);
        $criteria->withProjection($projection ?? self::fullProjection());
        $items = $this->repo->fetch($criteria, self::intArg($args['offset'] ?? null), self::intArg($args['limit'] ?? null));

        return self::itemsResponse($items, fn (Product $p) => $this->codec->writeEntity($p));
    }

    private function fetchPage(RestRequest $request): Response
    {
        $this->security->require('product', 'read');
        $criteria = new ProductCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['page', 'pageSize']);
        $criteria->withProjection($projection ?? self::fullProjection());
        $page = $this->repo->fetchPage($criteria, self::intArg($args['page'] ?? null), self::intArg($args['pageSize'] ?? null));

        return self::itemsResponse($page->items, fn (Product $p) => $this->codec->writeEntity($p), $page->totalItems);
    }

    private function fetchById(RestRequest $request): Response
    {
        $this->security->require('product', 'read');
        $product = $this->repo->fetchById(self::pathId($request), self::fullProjection());

        return $product === null ? self::notFound() : RestResponse::json($this->codec->writeEntity($product));
    }

    private function fetchByIdPost(RestRequest $request): Response
    {
        $this->security->require('product', 'read');
        [$id, $projection] = self::readFetchById($request->json(), $this->codec);
        $product = $this->repo->fetchById($id, $projection ?? self::fullProjection());

        return $product === null ? self::notFound() : RestResponse::json($this->codec->writeEntity($product));
    }

    /** Public: the catalog shows images without login. 204 = product without an image. */
    private function fetchImage(RestRequest $request): Response
    {
        try {
            $image = $this->repo->fetchImage(self::pathId($request));
        } catch (Throwable $e) {
            // a missing product lands here too, on purpose: REST clients read 404 as
            // "no image" (null) and only an error makes their fetchImage throw, like the local one
            if (!$e instanceof BusinessException) {
                $this->logger->error('Fetching product image', ['exception' => $e]);
            }

            return RestResponse::error(HttpStatus::INTERNAL_SERVER_ERROR, 'Failed to fetch image');
        }

        return $image === null
            ? new Response(HttpStatus::NO_CONTENT)
            : new Response(HttpStatus::OK, ['content-type' => 'image/png'], $image);
    }

    private function updateImage(RestRequest $request): Response
    {
        $this->security->require('product', 'write');
        $id = self::pathId($request);

        return RestResponse::json(['success' => $this->repo->updateImage($id, $request->body)]);
    }
}
