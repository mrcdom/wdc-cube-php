<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest\Security;

use Wdc\Framework\Domain\Criteria\ComparableCriterion;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\CurrentSecurityContext;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * Confere permissões nos endpoints REST. Sem AuthenticationService (segurança desligada:
 * testes, uso local) nada é conferido e o contexto devolvido é null.
 */
final readonly class SecurityEnforcer
{
    public function __construct(private ?AuthenticationService $auth)
    {
    }

    public function isActive(): bool
    {
        return $this->auth !== null;
    }

    /** Exige só um usuário autenticado. */
    public function requireAuthenticated(): ?SecurityContext
    {
        if ($this->auth === null) {
            return null;
        }

        return CurrentSecurityContext::get() ?? throw new AccessDeniedException('Authentication required');
    }

    /** Exige a permissão `entidade:operação` (ex.: `purchase:write`). */
    public function require(string $entity, string $operation): ?SecurityContext
    {
        $context = $this->requireAuthenticated();
        if ($context !== null && !$context->hasPermission($entity, $operation)) {
            throw new AccessDeniedException("Requires {$entity}:{$operation}");
        }

        return $context;
    }

    /** O usuário só enxerga os próprios dados? (autenticado e sem `data:all`) */
    public static function isScoped(?SecurityContext $context): bool
    {
        return $context !== null && !$context->hasDataAll();
    }

    /**
     * Restringe o critério de dono ao usuário do contexto.
     *
     * Se o cliente marcou o campo como disjuntivo (`"or": true`), os pedidos dele são descartados
     * antes: acrescentar `= meu id` a um OR alargaria a busca em vez de restringi-la —
     * `{"userId": {"or": true, "p": [{"o": "EQ", "v": [outro]}]}}` devolveria os dados do outro.
     *
     * @param ComparableCriterion<*, int> $owner
     */
    public static function forceOwner(ComparableCriterion $owner, int $userId): void
    {
        if ($owner->disjunctive()) {
            $owner->clear();
            $owner->and();
        }
        $owner->eq($userId);
    }
}
