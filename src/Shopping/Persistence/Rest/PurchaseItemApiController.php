<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest;

use Amp\Http\Server\Response;
use Closure;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Domain\Security\SecurityContext;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCodec;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemCriteria;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Persistence\Rest\Security\SecurityEnforcer;

/**
 * `/api/repo/purchase-item/*`. Without `data:all`, each user only sees and changes items of their own
 * purchases. Responses do not include the purchase (`purchaseId`).
 *
 * Every operation applies the scope — insert, update, delete, count, fetch, fetch-page and the
 * fetch-by-id ones — so nobody reads or writes items of someone else's purchases.
 */
final class PurchaseItemApiController extends ApiController
{
    private readonly PurchaseItemCodec $codec;

    private readonly PurchaseItemRepository $repo;

    private readonly PurchaseRepository $purchases;

    public function __construct(SecurityEnforcer $security)
    {
        parent::__construct($security);
        $this->repo = Beans::of(PurchaseItemRepository::class);
        $this->purchases = Beans::of(PurchaseRepository::class);
        $this->codec = new PurchaseItemCodec();
    }

    public function routes(string $prefix, Closure $add): void
    {
        $base = $prefix . '/api/repo/purchase-item';
        $add('POST', "{$base}/insert", $this->insert(...), true);
        $add('POST', "{$base}/update", $this->update(...), true);
        $add('POST', "{$base}/delete", $this->delete(...), true);
        $add('POST', "{$base}/count", $this->count(...), false);
        $add('POST', "{$base}/fetch", $this->fetch(...), false);
        $add('POST', "{$base}/fetch-page", $this->fetchPage(...), false);
        $add('POST', "{$base}/fetch-by-id", $this->fetchByIdPost(...), false);
        $add('GET', "{$base}/{id:\\d+}", $this->fetchById(...), false);
    }

    public static function fullProjection(): PurchaseItem
    {
        $product = (new Product())->withId(PV::INT)->withName(PV::STR)->withPrice(PV::FLOAT);

        return (new PurchaseItem())->withId(PV::INT)->withAmount(PV::INT)->withPrice(PV::FLOAT)->withProduct($product);
    }

    private function insert(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'write');
        $item = $this->codec->readEntity($request->json());
        if ($context !== null && SecurityEnforcer::isScoped($context)
            && !PurchaseApiController::owns($this->purchases, $item->purchaseId(), $context->userId())) {
            throw new AccessDeniedException("Cannot modify other user's data");
        }
        $success = $this->repo->insert($item);

        return self::insertResult($success, $item->id());
    }

    private function update(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'write');
        $data = $this->codec->readEntityForUpdate($request->json());
        if ($context !== null && SecurityEnforcer::isScoped($context)) {
            $item = $data->entity;
            $movesToOther = $item->purchase() !== null
                && !PurchaseApiController::owns($this->purchases, $item->purchaseId(), $context->userId());
            if ($movesToOther || !$this->owns($item->id(), $context->userId())) {
                throw new AccessDeniedException("Cannot modify other user's data");
            }
        }

        return RestResponse::json(['success' => $this->repo->update($data->entity, null, $data->projection)]);
    }

    private function delete(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'delete');
        $criteria = self::readCriteria($request->json(), $this->codec, new PurchaseItemCriteria());
        self::scopeCriteria($context, $criteria);

        return RestResponse::json(['count' => $this->repo->delete($criteria)]);
    }

    private function count(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'read');
        $criteria = self::readCriteria($request->json(), $this->codec, new PurchaseItemCriteria());
        self::scopeCriteria($context, $criteria);

        return RestResponse::json(['count' => $this->repo->count($criteria)]);
    }

    private function fetch(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'read');
        $criteria = new PurchaseItemCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['offset', 'limit']);
        self::scopeCriteria($context, $criteria);
        $criteria->withProjection($projection ?? self::fullProjection());
        $items = $this->repo->fetch($criteria, self::intArg($args['offset'] ?? null), self::intArg($args['limit'] ?? null));

        return self::itemsResponse($items, $this->write(...));
    }

    private function fetchPage(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'read');
        $criteria = new PurchaseItemCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['page', 'pageSize']);
        self::scopeCriteria($context, $criteria);
        $criteria->withProjection($projection ?? self::fullProjection());
        $page = $this->repo->fetchPage($criteria, self::intArg($args['page'] ?? null), self::intArg($args['pageSize'] ?? null));

        return self::itemsResponse($page->items, $this->write(...), $page->totalItems);
    }

    private function fetchById(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'read');

        return $this->fetchOne($context, self::pathId($request), self::fullProjection());
    }

    private function fetchByIdPost(RestRequest $request): Response
    {
        $context = $this->security->require('purchase-item', 'read');
        [$id, $projection] = self::readFetchById($request->json(), $this->codec);

        return $this->fetchOne($context, $id, $projection ?? self::fullProjection());
    }

    /** Another user's = 404, as if it did not exist. */
    private function fetchOne(?SecurityContext $context, ?int $id, PurchaseItem $projection): Response
    {
        if ($context !== null && SecurityEnforcer::isScoped($context) && !$this->owns($id, $context->userId())) {
            return self::notFound();
        }
        $item = $this->repo->fetchById($id, $projection);

        return $item === null ? self::notFound() : RestResponse::json($this->write($item));
    }

    private function write(PurchaseItem $item): object
    {
        return $this->codec->writeEntity($item->withPurchase(null));
    }

    /** Does the item exist and belong to one of the user's purchases? */
    private function owns(?int $itemId, int $userId): bool
    {
        return $itemId !== null
            && $this->repo->count((new PurchaseItemCriteria())->withPurchaseItemId($itemId)->withUserId($userId)) > 0;
    }

    private static function scopeCriteria(?SecurityContext $context, PurchaseItemCriteria $criteria): void
    {
        if ($context !== null && SecurityEnforcer::isScoped($context)) {
            SecurityEnforcer::forceOwner($criteria->userId(), $context->userId());
        }
    }
}
