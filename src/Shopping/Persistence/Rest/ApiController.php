<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Closure;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\Json;
use Wdc\Framework\Domain\Codec\ModelCodec;
use Wdc\Framework\Domain\Exception\InvalidRequestException;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;
use Wdc\Shopping\Persistence\Rest\Security\SecurityEnforcer;

/**
 * Base dos controllers de entidade: as rotas e o que se repete na leitura dos corpos.
 *
 * Todas as rotas seguem o mesmo molde (`/api/repo/<entidade>/<operação>`), com as mesmas
 * formas de pedido e de resposta — o que muda é o codec, a projeção padrão e a regra de escopo.
 */
abstract class ApiController
{
    public function __construct(protected readonly SecurityEnforcer $security)
    {
    }

    /**
     * Registra as rotas. `$add(método, caminho, endpoint, transacional)`.
     *
     * @param Closure(string, string, Closure(RestRequest): Response, bool): void $add
     */
    abstract public function routes(string $prefix, Closure $add): void;

    protected static function pathId(RestRequest $request): int
    {
        $id = filter_var($request->pathParam('id'), FILTER_VALIDATE_INT);

        return $id === false ? throw new InvalidRequestException('Invalid ID') : $id;
    }

    protected static function notFound(): Response
    {
        return RestResponse::error(HttpStatus::NOT_FOUND, 'Not found');
    }

    protected static function insertResult(bool $success, ?int $id): Response
    {
        return RestResponse::json(['success' => $success, 'id' => $id ?? -1]);
    }

    /**
     * Um corpo que é só critério (delete, count).
     *
     * @template E of object
     * @template C of object
     * @param ModelCodec<E, C> $codec
     * @param C $criteria
     * @return C
     */
    protected static function readCriteria(mixed $json, ModelCodec $codec, object $criteria): object
    {
        foreach (Json::fields($json) as $name => $value) {
            $codec->readCriteriaField($value, $name, $criteria);
        }

        return $criteria;
    }

    /**
     * Um corpo de busca: `projection`, os argumentos pedidos (offset/limit, page/pageSize...) e
     * os campos de critério. Campo desconhecido é ignorado.
     *
     * @template E of object
     * @template C of object
     * @param ModelCodec<E, C> $codec
     * @param C $criteria
     * @param list<string> $argNames
     * @return array{0: E|null, 1: array<string, mixed>} [projeção, argumentos]
     */
    protected static function readQuery(mixed $json, ModelCodec $codec, object $criteria, array $argNames): array
    {
        $projection = null;
        $args = [];
        foreach (Json::fields($json) as $name => $value) {
            if ($name === 'projection') {
                $projection = $value === null ? null : $codec->readEntity($value);
            } elseif (in_array($name, $argNames, true)) {
                $args[$name] = $value;
            } else {
                $codec->readCriteriaField($value, $name, $criteria);
            }
        }

        return [$projection, $args];
    }

    /**
     * Corpo do fetch-by-id em POST: `{id, projection}`.
     *
     * @template E of object
     * @template C of object
     * @param ModelCodec<E, C> $codec
     * @return array{0: int|null, 1: E|null}
     */
    protected static function readFetchById(mixed $json, ModelCodec $codec): array
    {
        $id = null;
        $projection = null;
        foreach (Json::fields($json) as $name => $value) {
            if ($name === 'id') {
                $id = InputCoerce::asInt($value);
            } elseif ($name === 'projection' && $value !== null) {
                $projection = $codec->readEntity($value);
            }
        }

        return [$id, $projection];
    }

    /**
     * `{"items": [...]}` ou, com total, `{"items": [...], "totalItems": n}`.
     *
     * @template E of object
     * @param list<E> $items
     * @param Closure(E): mixed $write
     */
    protected static function itemsResponse(array $items, Closure $write, ?int $totalItems = null): Response
    {
        $body = ['items' => array_map($write, $items)];
        if ($totalItems !== null) {
            $body['totalItems'] = $totalItems;
        }

        return RestResponse::json($body);
    }

    protected static function intArg(mixed $value): int
    {
        return InputCoerce::asInt($value, 0) ?? 0;
    }
}
