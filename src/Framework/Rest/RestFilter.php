<?php

declare(strict_types=1);

namespace Wdc\Framework\Rest;

use Amp\Http\Server\Response;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * Roda antes do handler. Devolve uma resposta para interromper a
 * requisição, ou o contexto de segurança com que o handler deve rodar (null = nenhum).
 */
interface RestFilter
{
    public function filter(RestRequest $request): Response|SecurityContext|null;
}
