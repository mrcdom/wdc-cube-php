<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest;

use Amp\Http\Server\Response;
use Closure;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Domain\Security\SecurityContext;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Purchase\Purchase;
use Wdc\Shopping\Domain\Purchase\PurchaseCodec;
use Wdc\Shopping\Domain\Purchase\PurchaseCriteria;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItem;
use Wdc\Shopping\Domain\User\User;
use Wdc\Shopping\Persistence\Rest\Security\SecurityEnforcer;

/**
 * `/api/repo/purchase/*`. Sem `data:all`, cada um só vê e altera as próprias compras.
 *
 * Toda operação confere o dono: o `update` recusa e o fetch-by-id esconde compras de outro
 * usuário, qualquer que seja a projeção pedida.
 */
final class PurchaseApiController extends ApiController
{
    private readonly PurchaseCodec $codec;

    public function __construct(
        SecurityEnforcer $security,
        private readonly PurchaseRepository $repo,
    ) {
        parent::__construct($security);
        $this->codec = new PurchaseCodec();
    }

    public function routes(string $prefix, Closure $add): void
    {
        $base = $prefix . '/api/repo/purchase';
        $add('POST', "{$base}/insert", $this->insert(...), true);
        $add('POST', "{$base}/update", $this->update(...), true);
        $add('POST', "{$base}/delete", $this->delete(...), true);
        $add('POST', "{$base}/count", $this->count(...), false);
        $add('POST', "{$base}/fetch", $this->fetch(...), false);
        $add('POST', "{$base}/fetch-page", $this->fetchPage(...), false);
        $add('POST', "{$base}/fetch-by-id", $this->fetchByIdPost(...), false);
        $add('GET', "{$base}/{id:\\d+}", $this->fetchById(...), false);
    }

    /** id, data, usuário (id, nome) e itens (id, quantidade, preço, produto resumido). */
    public static function fullProjectionWithItems(): Purchase
    {
        $product = (new Product())->withId(PV::INT)->withName(PV::STR)->withPrice(PV::FLOAT);
        $item = (new PurchaseItem())->withId(PV::INT)->withAmount(PV::INT)->withPrice(PV::FLOAT)->withProduct($product);

        return self::simpleProjection()->withItems([$item]);
    }

    /** Sem os itens. */
    public static function simpleProjection(): Purchase
    {
        return (new Purchase())
            ->withId(PV::INT)
            ->withBuyDate(PV::dateTime())
            ->withUser((new User())->withId(PV::INT)->withName(PV::STR));
    }

    private function insert(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'write');
        $purchase = $this->codec->readEntity($request->json());
        if ($context !== null && SecurityEnforcer::isScoped($context)) {
            // a compra é sempre do próprio usuário
            $purchase->withUser(($purchase->user() ?? new User())->withId($context->userId()));
        }
        $success = $this->repo->insert($purchase);

        return self::insertResult($success, $purchase->id());
    }

    private function update(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'write');
        $data = $this->codec->readEntityForUpdate($request->json());
        if ($context !== null && SecurityEnforcer::isScoped($context)) {
            $purchase = $data->entity;
            $movesToOther = $purchase->user() !== null && $purchase->userId() !== $context->userId();
            if ($movesToOther || !self::owns($this->repo, $purchase->id(), $context->userId())) {
                throw new AccessDeniedException("Cannot modify other user's data");
            }
        }

        return RestResponse::json(['success' => $this->repo->update($data->entity, null, $data->projection)]);
    }

    private function delete(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'delete');
        $criteria = self::readCriteria($request->json(), $this->codec, new PurchaseCriteria());
        self::scopeCriteria($context, $criteria);

        return RestResponse::json(['count' => $this->repo->delete($criteria)]);
    }

    private function count(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'read');
        $criteria = self::readCriteria($request->json(), $this->codec, new PurchaseCriteria());
        self::scopeCriteria($context, $criteria);

        return RestResponse::json(['count' => $this->repo->count($criteria)]);
    }

    private function fetch(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'read');
        $criteria = new PurchaseCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['offset', 'limit', 'includeItems']);
        self::scopeCriteria($context, $criteria);
        $criteria->withProjection($projection ?? self::defaultProjection($args));
        $items = $this->repo->fetch($criteria, self::intArg($args['offset'] ?? null), self::intArg($args['limit'] ?? null));

        return self::itemsResponse($items, fn (Purchase $p) => $this->codec->writeEntity($p));
    }

    private function fetchPage(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'read');
        $criteria = new PurchaseCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['page', 'pageSize', 'includeItems']);
        self::scopeCriteria($context, $criteria);
        $criteria->withProjection($projection ?? self::defaultProjection($args));
        $page = $this->repo->fetchPage($criteria, self::intArg($args['page'] ?? null), self::intArg($args['pageSize'] ?? null));

        return self::itemsResponse($page->items, fn (Purchase $p) => $this->codec->writeEntity($p), $page->totalItems);
    }

    private function fetchById(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'read');

        return $this->fetchOne($context, self::pathId($request), self::fullProjectionWithItems());
    }

    private function fetchByIdPost(RestRequest $request): Response
    {
        $context = $this->security->require('purchase', 'read');
        [$id, $projection] = self::readFetchById($request->json(), $this->codec);

        return $this->fetchOne($context, $id, $projection ?? self::fullProjectionWithItems());
    }

    /** De outro usuário = 404, como se não existisse. */
    private function fetchOne(?SecurityContext $context, ?int $id, Purchase $projection): Response
    {
        if ($context !== null && SecurityEnforcer::isScoped($context) && !self::owns($this->repo, $id, $context->userId())) {
            return self::notFound();
        }
        $purchase = $this->repo->fetchById($id, $projection);

        return $purchase === null ? self::notFound() : RestResponse::json($this->codec->writeEntity($purchase));
    }

    /** A compra existe e é do usuário? (consulta de contagem, pelo critério de dono) */
    public static function owns(PurchaseRepository $repo, ?int $purchaseId, int $userId): bool
    {
        return $purchaseId !== null
            && $repo->count((new PurchaseCriteria())->withPurchaseId($purchaseId)->withUserId($userId)) > 0;
    }

    private static function scopeCriteria(?SecurityContext $context, PurchaseCriteria $criteria): void
    {
        if ($context !== null && SecurityEnforcer::isScoped($context)) {
            SecurityEnforcer::forceOwner($criteria->userId(), $context->userId());
        }
    }

    /** @param array<string, mixed> $args */
    private static function defaultProjection(array $args): Purchase
    {
        // includeItems: true se ausente; presente, só true conta como true
        $includeItems = !array_key_exists('includeItems', $args) || InputCoerce::asBool($args['includeItems']) === true;

        return $includeItems ? self::fullProjectionWithItems() : self::simpleProjection();
    }
}
