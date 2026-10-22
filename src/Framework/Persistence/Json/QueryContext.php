<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence\Json;

/**
 * Contexto de uma consulta: gera aliases únicos (p1, u2, pi3...) para que cada subconsulta
 * correlacionada enxergue a sua tabela e a do pai sem ambiguidade.
 */
final class QueryContext
{
    private int $counter = 0;

    public function alias(string $prefix): string
    {
        return $prefix . ++$this->counter;
    }
}
