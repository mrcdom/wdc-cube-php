<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;

/**
 * Descrição de uma tabela: nome, fábrica do bean e colunas. A primeira coluna é a chave.
 * Usada na escrita (INSERT/UPDATE) e, pela JsonQuery, na leitura.
 *
 * @template E of object
 */
final readonly class Table
{
    /**
     * @param Closure(): E            $factory
     * @param non-empty-list<Column<E>> $columns   a primeira é o ID
     */
    public function __construct(
        public string $name,
        private Closure $factory,
        public array $columns,
    ) {
    }

    /** @return E */
    public function newBean(): object
    {
        return ($this->factory)();
    }

    /** @return Column<E> */
    public function id(): Column
    {
        return $this->columns[0];
    }

    /**
     * Colunas pedidas pela projeção (campo não-null); o ID entra sempre.
     *
     * @param E $projection
     * @return non-empty-list<Column<E>>
     */
    public function projected(object $projection): array
    {
        $result = [$this->id()];
        foreach (array_slice($this->columns, 1) as $column) {
            if ($column->read($projection) !== null) {
                $result[] = $column;
            }
        }

        return $result;
    }

    /**
     * true quando a projeção não pede nenhuma coluna além do ID (as relações quem confere é a JsonQuery).
     *
     * @param E $projection
     */
    public function isIdOnly(object $projection): bool
    {
        foreach (array_slice($this->columns, 1) as $column) {
            if ($column->read($projection) !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Column<E>>      $columns
     * @return E
     */
    public function hydrate(array $row, array $columns): object
    {
        $bean = $this->newBean();
        foreach ($columns as $column) {
            $column->write($bean, $row[$column->column] ?? null);
        }

        return $bean;
    }
}
