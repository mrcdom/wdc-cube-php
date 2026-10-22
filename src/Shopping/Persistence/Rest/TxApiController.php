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
 * `/api/tx/*` — a transação remota dirigida pelo cliente: `begin` → txId; as escritas com o
 * cabeçalho `X-Tx-Id` entram nela; `commit` ou `rollback` a encerram; `status` desfaz a dúvida
 * de uma resposta perdida.
 *
 * O dono é o próprio coordenador quem guarda e confere: outro usuário não consegue usar o txId.
 */
final readonly class TxApiController
{
    public const string TX_HEADER = 'X-Tx-Id';

    /** Id opaco da instância do app cliente: dono das transações quando não há usuário. */
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
     * A chave de dono do solicitante, em espaços separados para um anônimo não se passar por um
     * usuário: autenticado → `user:<id>` (o X-Client-Id é ignorado); sem usuário → `anon:<X-Client-Id>`,
     * ou null sem o cabeçalho.
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
            throw new AccessDeniedException('Cabeçalho ' . self::TX_HEADER . ' ausente');
        }

        return $txId;
    }

    private function coordinator(): RemoteTransactionCoordinator
    {
        return $this->coordinator ?? throw new RuntimeException('Coordenador de transação remota não inicializado');
    }
}
