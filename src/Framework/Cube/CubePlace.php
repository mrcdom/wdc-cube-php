<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Nó da árvore de navegação. Na aplicação costuma ser um `enum Place: int implements CubePlace`.
 *
 * Invariante: o id cresce com a profundidade — a liberação é feita
 * da folha para a raiz em ordem decrescente de id.
 */
interface CubePlace
{
    public function id(): int;

    public function name(): string;

    public function createPresenter(CubeApplication $app): CubePresenter;
}
