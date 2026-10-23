<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence\Rest;

use Amp\Http\Server\Response;
use Closure;
use Psr\Log\LoggerInterface;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Security\CurrentSecurityContext;
use Wdc\Framework\Domain\Security\SecurityContext;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;
use Wdc\Store\Domain\User\User;
use Wdc\Store\Domain\User\UserCodec;
use Wdc\Store\Domain\User\UserCriteria;
use Wdc\Store\Domain\User\UserRepository;
use Wdc\Store\Persistence\Rest\Security\SecurityEnforcer;

/**
 * `/api/repo/user/*`. The password never leaves: it is cleared in every response and removed from projections.
 * Without `data:all`, each user only sees and changes their own user.
 */
final class UserApiController extends ApiController
{
    private readonly UserCodec $codec;

    private readonly UserRepository $repo;

    public function __construct(
        SecurityEnforcer $security,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($security);
        $this->repo = Beans::of(UserRepository::class);
        $this->codec = new UserCodec();
    }

    public function routes(string $prefix, Closure $add): void
    {
        $base = $prefix . '/api/repo/user';
        $add('POST', "{$base}/insert", $this->insert(...), true);
        $add('POST', "{$base}/update", $this->update(...), true);
        $add('POST', "{$base}/delete", $this->delete(...), true);
        $add('POST', "{$base}/count", $this->count(...), false);
        $add('POST', "{$base}/fetch", $this->fetch(...), false);
        $add('POST', "{$base}/fetch-page", $this->fetchPage(...), false);
        $add('POST', "{$base}/fetch-by-id", $this->fetchByIdPost(...), false);
        $add('GET', "{$base}/{id:\\d+}", $this->fetchById(...), false);
    }

    private function insert(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'write');
        $user = $this->codec->readEntity($request->json());
        $this->decryptPasswordIfPresent($user);
        self::enforceUserScope($context, $user);
        $success = $this->repo->insert($user);

        return self::insertResult($success, $user->id());
    }

    private function update(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'write');
        $data = $this->codec->readEntityForUpdate($request->json());
        $this->decryptPasswordIfPresent($data->entity);
        self::enforceUserScope($context, $data->entity);

        return RestResponse::json(['success' => $this->repo->update($data->entity, null, $data->projection)]);
    }

    private function delete(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'delete');
        $criteria = self::readCriteria($request->json(), $this->codec, new UserCriteria());
        self::scopeCriteria($context, $criteria);

        return RestResponse::json(['count' => $this->repo->delete($criteria)]);
    }

    private function count(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'read');
        $criteria = self::readCriteria($request->json(), $this->codec, new UserCriteria());
        self::scopeCriteria($context, $criteria);

        return RestResponse::json(['count' => $this->repo->count($criteria)]);
    }

    private function fetch(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'read');
        $criteria = new UserCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['offset', 'limit']);
        $criteria->withProjection($projection?->withPassword(null));
        self::scopeCriteria($context, $criteria);
        $items = $this->repo->fetch($criteria, self::intArg($args['offset'] ?? null), self::intArg($args['limit'] ?? null));

        return self::itemsResponse($items, $this->write(...));
    }

    private function fetchPage(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'read');
        $criteria = new UserCriteria();
        [$projection, $args] = self::readQuery($request->json(), $this->codec, $criteria, ['page', 'pageSize']);
        $criteria->withProjection($projection?->withPassword(null));
        self::scopeCriteria($context, $criteria);
        $page = $this->repo->fetchPage($criteria, self::intArg($args['page'] ?? null), self::intArg($args['pageSize'] ?? null));

        return self::itemsResponse($page->items, $this->write(...), $page->totalItems);
    }

    private function fetchById(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'read');
        $id = self::pathId($request);
        if (SecurityEnforcer::isScoped($context) && $id !== $context?->userId()) {
            return self::notFound();
        }
        $user = $this->repo->fetchById($id);

        return $user === null ? self::notFound() : RestResponse::json($this->write($user));
    }

    private function fetchByIdPost(RestRequest $request): Response
    {
        $context = $this->security->require('user', 'read');
        [$id, $projection] = self::readFetchById($request->json(), $this->codec);
        if (SecurityEnforcer::isScoped($context) && $id !== null && $id !== $context?->userId()) {
            return self::notFound();
        }
        $user = $this->repo->fetchById($id, $projection?->withPassword(null));

        return $user === null ? self::notFound() : RestResponse::json($this->write($user));
    }

    private function write(User $user): object
    {
        return $this->codec->writeEntity($user->withPassword(null));
    }

    private static function scopeCriteria(?SecurityContext $context, UserCriteria $criteria): void
    {
        if ($context !== null && SecurityEnforcer::isScoped($context)) {
            SecurityEnforcer::forceOwner($criteria->userId(), $context->userId());
        }
    }

    /** Without `data:all` a user can only save themselves. */
    private static function enforceUserScope(?SecurityContext $context, User $user): void
    {
        if ($context !== null && SecurityEnforcer::isScoped($context) && $user->id() !== null && $user->id() !== $context->userId()) {
            throw new AccessDeniedException("Cannot modify other user's data");
        }
    }

    /**
     * The password may come encrypted with the session's public key (RSA-OAEP with SHA-256, Base64) —
     * the one login returns in `publicKey`. If it does not decrypt, it is used as received.
     */
    private function decryptPasswordIfPresent(User $user): void
    {
        $context = CurrentSecurityContext::get();
        $password = $user->password();
        if ($context === null || $password === null || trim($password) === '') {
            return;
        }
        $cipher = base64_decode($password, true);
        if ($cipher !== false
            && openssl_private_decrypt($cipher, $plain, $context->privateKey(), OPENSSL_PKCS1_OAEP_PADDING, 'sha256')
            && is_string($plain)) {
            $user->withPassword($plain);
        } else {
            $this->logger->debug('Password not RSA-encrypted or decryption failed, using as-is');
        }
    }
}
