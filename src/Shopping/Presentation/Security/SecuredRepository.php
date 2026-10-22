<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Security;

use Closure;
use Wdc\Framework\Domain\Pagination\Page;
use Wdc\Framework\Domain\Repository\Repository;
use Wdc\Framework\Domain\Security\CurrentSecurityContext;
use Wdc\Framework\Domain\Security\SecurityContext;

/**
 * Embrulha um repositório e, em cada chamada, põe o SecurityContext da sessão como
 * contexto corrente.
 *
 * PHP não gera classes
 * para uma interface em tempo de execução, então há esta base genérica e uma subclasse
 * pequena por repositório (que só declara qual interface implementa).
 *
 * @template E of object
 * @template C of object
 * @implements Repository<E, C>
 */
abstract class SecuredRepository implements Repository
{
    /**
     * @param Repository<E, C>               $inner
     * @param Closure(): ?SecurityContext     $context lê o contexto da sessão a cada chamada
     */
    public function __construct(
        protected readonly Repository $inner,
        private readonly Closure $context,
    ) {
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    protected function run(Closure $work): mixed
    {
        return CurrentSecurityContext::runAs(($this->context)(), $work);
    }

    public function insert(object $bean): bool
    {
        return $this->run(fn () => $this->inner->insert($bean));
    }

    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool
    {
        return $this->run(fn () => $this->inner->update($newBean, $oldBean, $projection));
    }

    public function insertOrUpdate(object $newBean, ?object $oldBean): bool
    {
        return $this->run(fn () => $this->inner->insertOrUpdate($newBean, $oldBean));
    }

    public function delete(object $criteria): int
    {
        return $this->run(fn () => $this->inner->delete($criteria));
    }

    public function count(object $criteria): int
    {
        return $this->run(fn () => $this->inner->count($criteria));
    }

    public function fetch(object $criteria, int $offset = 0, int $limit = 0): array
    {
        return $this->run(fn () => $this->inner->fetch($criteria, $offset, $limit));
    }

    public function fetchPage(object $criteria, int $page, int $pageSize): Page
    {
        return $this->run(fn () => $this->inner->fetchPage($criteria, $page, $pageSize));
    }

    public function fetchById(?int $id, ?object $projection = null): ?object
    {
        return $this->run(fn () => $this->inner->fetchById($id, $projection));
    }

    public function newProjection(): object
    {
        return $this->inner->newProjection();
    }
}
