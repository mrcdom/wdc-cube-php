<?php

declare(strict_types=1);

namespace Wdc\Framework\Rest;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Exception\InvalidRequestException;
use Wdc\Framework\Domain\Exception\TransactionConflictException;
use Wdc\Framework\Domain\Exception\TransactionLimitExceededException;
use Wdc\Framework\Domain\Security\CurrentSecurityContext;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * Adapta um endpoint `Closure(RestRequest): Response` ao servidor HTTP do Amp:
 *
 * 1. lê o corpo (a única espera);
 * 2. roda o filtro (segurança), que pode interromper ou dar o contexto de segurança;
 * 3. roda o endpoint com esse contexto como o corrente — síncrono, sem outra Fiber no meio;
 * 4. traduz as exceções nos códigos HTTP da API.
 */
final readonly class RestHandler implements RequestHandler
{
    /** Limite padrão do corpo: 1 MiB. */
    public const int DEFAULT_BODY_LIMIT = 1_048_576;

    /**
     * @param Closure(RestRequest): Response $endpoint
     */
    public function __construct(
        private Closure $endpoint,
        private ?RestFilter $filter,
        private LoggerInterface $logger,
        private int $bodyLimit = self::DEFAULT_BODY_LIMIT,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        // erro ao ler o corpo (cliente caiu, corpo grande demais) segue para o Amp, que responde
        return $this->handle(RestRequest::from($request, $this->bodyLimit));
    }

    /** O mesmo caminho, sem HTTP (testes). */
    public function handle(RestRequest $request): Response
    {
        try {
            $context = null;
            if ($this->filter !== null) {
                $result = $this->filter->filter($request);
                if ($result instanceof Response) {
                    return $result;
                }
                $context = $result;
            }

            return self::runAs($context, fn () => ($this->endpoint)($request));
        } catch (Throwable $e) {
            return $this->toResponse($e);
        }
    }

    /**
     * Ao contrário do `runAs` comum, aqui o contexto é sempre trocado — inclusive para null —, para
     * uma requisição anônima nunca herdar o contexto de outra.
     *
     * @param Closure(): Response $work
     */
    private static function runAs(?SecurityContext $context, Closure $work): Response
    {
        $previous = CurrentSecurityContext::get();
        CurrentSecurityContext::set($context);
        try {
            return $work();
        } finally {
            CurrentSecurityContext::set($previous);
        }
    }

    private function toResponse(Throwable $e): Response
    {
        return match (true) {
            $e instanceof AccessDeniedException => RestResponse::error(HttpStatus::FORBIDDEN, $e->getMessage()),
            $e instanceof InvalidRequestException => RestResponse::error(HttpStatus::BAD_REQUEST, $e->getMessage()),
            $e instanceof TransactionConflictException => RestResponse::error(HttpStatus::CONFLICT, $e->getMessage()),
            $e instanceof TransactionLimitExceededException => RestResponse::error(HttpStatus::TOO_MANY_REQUESTS, $e->getMessage()),
            default => $this->internalError($e),
        };
    }

    private function internalError(Throwable $e): Response
    {
        $this->logger->error('Unhandled exception in request processing', ['exception' => $e]);

        return RestResponse::error(HttpStatus::INTERNAL_SERVER_ERROR, 'Internal server error');
    }
}
