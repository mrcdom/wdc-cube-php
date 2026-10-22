<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\User;

use Wdc\Framework\Domain\Criteria\ComparableCriterion;
use Wdc\Framework\Domain\Criteria\Criteria;
use Wdc\Framework\Domain\Criteria\TextCriterion;

/**
 * Critério de busca de User. Cada campo filtrável é um Criterion que já nasce criado
 * (nunca null): use hasUserId() para saber se foi informado.
 */
final class UserCriteria implements Criteria
{
    private ?User $projection = null;

    private ?UserOrderBy $orderBy = null;

    /** @var ComparableCriterion<self, int> */
    private readonly ComparableCriterion $userId;
    /** @var TextCriterion<self> */
    private readonly TextCriterion $userName;
    /** @var TextCriterion<self> */
    private readonly TextCriterion $name;
    /** @var TextCriterion<self> */
    private readonly TextCriterion $roles;

    public function __construct()
    {
        // Em PHP o valor inicial de uma propriedade precisa ser constante;
        // objetos que dependem de $this são criados no construtor.
        $this->userId = new ComparableCriterion($this, 'userId');
        $this->userName = new TextCriterion($this, 'userName');
        $this->name = new TextCriterion($this, 'name');
        $this->roles = new TextCriterion($this, 'roles');
    }

    public function projection(): ?User
    {
        return $this->projection;
    }

    public function withProjection(?User $projection): static
    {
        $this->projection = $projection;

        return $this;
    }

    public function orderBy(): ?UserOrderBy
    {
        return $this->orderBy;
    }

    public function withOrderBy(?UserOrderBy $orderBy): static
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    /** @return ComparableCriterion<self, int> */
    public function userId(): ComparableCriterion
    {
        return $this->userId;
    }

    public function hasUserId(): bool
    {
        return $this->userId->isSet();
    }

    /** Atalho para userId()->eq($value). */
    public function withUserId(?int $value): static
    {
        return $value === null ? $this : $this->userId->eq($value);
    }

    /** @return TextCriterion<self> */
    public function userName(): TextCriterion
    {
        return $this->userName;
    }

    public function hasUserName(): bool
    {
        return $this->userName->isSet();
    }

    /** Atalho para userName()->eq($value). */
    public function withUserName(?string $value): static
    {
        return $value === null ? $this : $this->userName->eq($value);
    }

    /** @return TextCriterion<self> */
    public function name(): TextCriterion
    {
        return $this->name;
    }

    public function hasName(): bool
    {
        return $this->name->isSet();
    }

    /** Atalho para name()->eq($value). */
    public function withName(?string $value): static
    {
        return $value === null ? $this : $this->name->eq($value);
    }

    /** @return TextCriterion<self> */
    public function roles(): TextCriterion
    {
        return $this->roles;
    }

    public function hasRoles(): bool
    {
        return $this->roles->isSet();
    }

    /** Atalho para roles()->eq($value). */
    public function withRoles(?string $value): static
    {
        return $value === null ? $this : $this->roles->eq($value);
    }

    public function criterions(): array
    {
        return [$this->userId, $this->userName, $this->name, $this->roles];
    }
}
