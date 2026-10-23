<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest;

use Amp\Http\Server\Response;
use Closure;
use RuntimeException;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Security\CurrentSecurityContext;
use Wdc\Framework\Persistence\Transaction\RemoteTransactionCoordinator;
use Wdc\Framework\Rest\RestRequest;
use Wdc\Framework\Rest\RestResponse;
use Wdc\Shopping\Persistence\Rest\Security\SecurityEnforcer;

/**
 * `/api/tx/*` — the client-driven remote transaction: `begin` → txId; writes carrying the
 * `X-Tx-Id` header join it; `commit` or `rollback` ends it; `status` resolves the doubt left by
 * a lost response.
 *
 * The coordinator itself stores and checks the owner: another user cannot use the txId.
 */
final readonly class TxApiController
{
    public const string TX_HEADER = 'X-Tx-Id';

    /** Opaque id of the client app instance: owns the transactions when there is no user. */
    public const string CLIENT_HEADER = 'X-Client-Id';

    public function __construct(
        private SecurityEnforcer $security,
        private ?RemoteTransactionCoordinator $coordinator,
    ) {
    }

    /** @param Closure(string, string, Closure(RestRequest): Response): void $add */
    public function routes(string $prefix, Closure $add): void
    {
        $add('POST', "{$prefix}/api/tx/begin", $this->begin(...));
        $add('POST', "{$prefix}/api/tx/commit", $this->commit(...));
        $add('POST', "{$prefix}/api/tx/rollback", $this->rollback(...));
        $add('GET', "{$prefix}/api/tx/status", $this->status(...));
    }

    /**
     * The requester's owner key, in separate namespaces so an anonymous client cannot pass as a
     * user: authenticated → `user:<id>` (X-Client-Id is ignored); no user → `anon:<X-Client-Id>`,
     * or null without the header.
     */
    public static function currentOwnerKey(RestRequest $request): ?string
    {
        $context = CurrentSecurityContext::get();
        if ($context !== null) {
            return 'user:' . $context->userId();
        }
        $clientId = $request->header(self::CLIENT_HEADER);

        return $clientId !== null && trim($clientId) !== '' ? 'anon:' . $clientId : null;
    }

    private function begin(RestRequest $request): Response
    {
        $this->security->requireAuthenticated();

        return RestResponse::json(['txId' => $this->coordinator()->begin(self::currentOwnerKey($request))]);
    }

    private function commit(RestRequest $request): Response
    {
        $this->coordinator()->commit(self::txId($request), self::currentOwnerKey($request));

        return RestResponse::json(['status' => 'committed']);
    }

    private function rollback(RestRequest $request): Response
    {
        $this->coordinator()->rollback(self::txId($request), self::currentOwnerKey($request));

        return RestResponse::json(['status' => 'rolledback']);
    }

    private function status(RestRequest $request): Response
    {
        return RestResponse::json(['status' => $this->coordinator()->status(self::txId($request), self::currentOwnerKey($request))]);
    }

    private static function txId(RestRequest $request): string
    {
        $txId = $request->header(self::TX_HEADER);
        if ($txId === null || trim($txId) === '') {
            throw new AccessDeniedException('Missing ' . self::TX_HEADER . ' header');
        }

        return $txId;
    }

    private function coordinator(): RemoteTransactionCoordinator
    {
        return $this->coordinator ?? throw new RuntimeException('Remote transaction coordinator not initialized');
    }
}
