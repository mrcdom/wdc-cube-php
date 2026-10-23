<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence\Rest\Security;

use Wdc\Framework\Domain\Criteria\ComparableCriterion;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\CurrentSecurityContext;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * Checks permissions on the REST endpoints. Without an AuthenticationService (security off:
 * tests, local use) nothing is checked and the returned context is null.
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

    /** Requires just an authenticated user. */
    public function requireAuthenticated(): ?SecurityContext
    {
        if ($this->auth === null) {
            return null;
        }

        return CurrentSecurityContext::get() ?? throw new AccessDeniedException('Authentication required');
    }

    /** Requires the `entity:operation` permission (e.g. `purchase:write`). */
    public function require(string $entity, string $operation): ?SecurityContext
    {
        $context = $this->requireAuthenticated();
        if ($context !== null && !$context->hasPermission($entity, $operation)) {
            throw new AccessDeniedException("Requires {$entity}:{$operation}");
        }

        return $context;
    }

    /** Does the user see only their own data? (authenticated and without `data:all`) */
    public static function isScoped(?SecurityContext $context): bool
    {
        return $context !== null && !$context->hasDataAll();
    }

    /**
     * Restricts the owner criterion to the context's user.
     *
     * If the client marked the field as disjunctive (`"or": true`), its predicates are discarded
     * first: appending `= my id` to an OR would widen the search instead of narrowing it —
     * `{"userId": {"or": true, "p": [{"o": "EQ", "v": [other]}]}}` would return the other user's data.
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
