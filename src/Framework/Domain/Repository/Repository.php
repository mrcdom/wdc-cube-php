<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Repository;

use Wdc\Framework\Domain\Pagination\Page;

/**
 * Contrato genérico de repositório.
 *
 * PHP não tem sobrecarga de métodos, então as variações
 * `update(new)`, `update(new, old)` e `update(new, old, prj)` viram um só método
 * com parâmetros opcionais. O mesmo para `fetch`.
 *
 * @template E of object  entidade
 * @template C of object  critério
 */
interface Repository
{
    /**
     * Insere; gera o id se for null e o grava no próprio bean.
     *
     * @param E $bean
     */
    public function insert(object $bean): bool;

    /**
     * Atualiza só os campos projetados E alterados em relação a $oldBean.
     *
     * @param E      $newBean
     * @param E|null $oldBean    null ⇒ todos os campos projetados
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
     * @param int $offset 0 = sem deslocamento
     * @param int $limit  0 = sem limite
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

    /** @return E projeção padrão: todos os campos rasos */
    public function newProjection(): object;
}
