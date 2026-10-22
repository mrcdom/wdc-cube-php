<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

use DateTimeImmutable;
use Wdc\Framework\Commons\Serialization\InputCoerce;
use Wdc\Framework\Commons\Serialization\IsoDateTime;
use Wdc\Framework\Domain\Exception\InvalidRequestException;

/**
 * O tipo do valor de um campo de critério no fio: um caso por tipo.
 */
enum CriterionValue
{
    case INT;
    case FLOAT;
    case STRING;
    case BOOL;
    /** data e hora em texto ISO-8601 com deslocamento */
    case DATETIME;

    public function write(mixed $value): mixed
    {
        return match ($this) {
            self::DATETIME => $value instanceof DateTimeImmutable ? IsoDateTime::format($value) : throw new InvalidRequestException('Data esperada'),
            self::FLOAT => is_int($value) || is_float($value) ? (float) $value : $value,
            default => $value,
        };
    }

    public function read(mixed $value): mixed
    {
        return match ($this) {
            self::INT => InputCoerce::asInt($value),
            self::FLOAT => InputCoerce::asFloat($value),
            self::STRING => InputCoerce::asString($value),
            self::BOOL => InputCoerce::asBool($value),
            self::DATETIME => ($text = InputCoerce::asString($value)) === null ? null : IsoDateTime::parse($text),
        };
    }
}
