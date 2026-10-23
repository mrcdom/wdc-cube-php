<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Repository;

use Wdc\Framework\Domain\Pagination\Page;

/**
 * Generic repository contract.
 *
 * PHP has no method overloading, so the variants
 * `update(new)`, `update(new, old)` and `update(new, old, prj)` become a single method
 * with optional parameters. Same for `fetch`.
 *
 * @template E of object  entity
 * @template C of object  criteria
 */
interface Repository
{
    /**
     * Inserts; generates the id if null and stores it in the bean itself.
     *
     * @param E $bean
     */
    public function insert(object $bean): bool;

    /**
     * Updates only the fields that are projected AND changed relative to $oldBean.
     *
     * @param E      $newBean
     * @param E|null $oldBean    null ⇒ all projected fields
     * @param E|null $projection null ⇒ newProjection()
     */
    public function update(object $newBean, ?object $oldBean = null, ?object $projection = null): bool;

    /**
     * @param E      $newBean
     * @param E|null $oldBean null ⇒ insert
     */
    public function insertOrUpdate(object $newBean, ?object $oldBean): bool;

    /** @param C $criteria */
    public function delete(object $criteria): int;

    /** @param C $criteria */
    public function count(object $criteria): int;

    /**
     * @param C $criteria
     * @param int $offset 0 = no offset
     * @param int $limit  0 = no limit
     * @return list<E>
     */
    public function fetch(object $criteria, int $offset = 0, int $limit = 0): array;

    /**
     * @param C $criteria
     * @return Page<E>
     */
    public function fetchPage(object $criteria, int $page, int $pageSize): Page;

    /**
     * @param E|null $projection
     * @return E|null
     */
    public function fetchById(?int $id, ?object $projection = null): ?object;

    /** @return E default projection: all shallow fields */
    public function newProjection(): object;
}
