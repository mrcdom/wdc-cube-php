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
 * Adapts a `Closure(RestRequest): Response` endpoint to Amp's HTTP server:
 *
 * 1. reads the body (the only wait);
 * 2. runs the (security) filter, which may short-circuit or supply the security context;
 * 3. runs the endpoint with that context as the current one — synchronously, with no other Fiber in between;
 * 4. maps exceptions to the API's HTTP status codes.
 */
final readonly class RestHandler implements RequestHandler
{
    /** Default body limit: 1 MiB. */
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
        // an error reading the body (client gone, body too large) propagates to Amp, which responds
        return $this->handle(RestRequest::from($request, $this->bodyLimit));
    }

    /** The same path, without HTTP (tests). */
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
     * Unlike the regular `runAs`, here the context is always replaced — even with null — so that
     * an anonymous request never inherits another request's context.
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
