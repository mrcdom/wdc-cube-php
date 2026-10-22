<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Projection;

use DateTimeImmutable;

/**
 * Valores-sentinela para montar projeções.
 *
 * A projeção é a própria entidade usada como "máscara": campo não-null = coluna a selecionar.
 * O valor em si não importa; estes são só convenção (compartilhada com os clientes).
 */
final class ProjectionValues
{
    public const bool BOOL = true;
    public const int INT = 1;
    public const float FLOAT = 1.0;
    public const string STR = '~';
    public const string BIN = '';

    private function __construct()
    {
    }

    /** Objetos não podem ser constantes de classe em PHP, por isso data é um método. */
    public static function dateTime(): DateTimeImmutable
    {
        static $value = null;   // variável estática de função: criada uma vez, reaproveitada

        return $value ??= new DateTimeImmutable('@0');
    }

    /**
     * @template E of object
     * @param E $shape
     * @return ProjectionList<E>
     */
    public static function singletonList(object $shape, ?object $criteria = null): ProjectionList
    {
        return new ProjectionList($shape, $criteria);
    }
}
