<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ReflectionClass;
use ReflectionProperty;
use Stringable;
use UnitEnum;
use Wdc\Framework\Cube\CubeView;

/**
 * ViewState → array ready for `json_encode`.
 *
 * Top-level rules (the contract with React):
 * - `"#"` = view id, always first;
 * - public non-static properties, except those marked with #[Transient];
 * - non-nullable `bool` is always emitted; non-nullable `int`/`float` are always emitted (including 0);
 * - `null` is omitted; a blank string is omitted;
 * - a CubeView becomes `"<name>Id": "<view id>"` (e.g. contentView → contentViewId).
 *
 * Inside lists and objects: null stays `null`, a CubeView becomes the bare id, strings are not filtered,
 * objects are emitted with their non-null public properties.
 */
final class ViewStateSerializer
{
    /** @var array<class-string, list<ReflectionProperty>> reflection cache per class */
    private static array $properties = [];

    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function serialize(object $state, string $instanceId): array
    {
        $out = ['#' => $instanceId];
        foreach (self::propertiesOf($state) as $property) {
            $value = $property->getValue($state);
            $name = $property->getName();
            if ($value instanceof CubeView) {
                $out["{$name}Id"] = $value->instanceId();
            } elseif ($value === null) {
                continue;
            } elseif (is_string($value)) {
                if (trim($value) !== '') {
                    $out[$name] = $value;
                }
            } else {
                $out[$name] = self::value($value);
            }
        }

        return $out;
    }

    /** JSON in the format clients expect: no escaping of "/" or Unicode, 10.0 stays 10.0. */
    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function value(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value), is_string($value) => $value,
            $value instanceof CubeView => $value->instanceId(),
            $value instanceof DateTimeInterface => self::isoUtc($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => self::arrayValue($value),
            is_object($value) => self::objectValue($value),
            default => null,
        };
    }

    /** ISO-8601 in UTC; the fraction only appears when it is not zero. */
    private static function isoUtc(DateTimeInterface $date): string
    {
        $utc = DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'));
        $fraction = $utc->format('u') === '000000' ? '' : '.' . rtrim($utc->format('u'), '0');

        return $utc->format('Y-m-d\TH:i:s') . $fraction . 'Z';
    }

    /**
     * List ⇒ JSON array; map ⇒ JSON object (stdClass, so an empty `[]` does not become an object or vice versa).
     *
     * @param array<mixed> $value
     */
    private static function arrayValue(array $value): mixed
    {
        if (array_is_list($value)) {
            return array_map(self::value(...), $value);
        }
        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::value($item);
        }

        return $object;
    }

    private static function objectValue(object $value): mixed
    {
        $properties = self::propertiesOf($value);
        if ($properties === [] && $value instanceof Stringable) {
            return (string) $value;
        }
        $object = new \stdClass();
        foreach ($properties as $property) {
            $item = $property->getValue($value);
            if ($item !== null) {
                $object->{$property->getName()} = self::value($item);
            }
        }

        return $object;
    }

    /** @return list<ReflectionProperty> */
    private static function propertiesOf(object $object): array
    {
        return self::$properties[$object::class] ??= array_values(array_filter(
            new ReflectionClass($object)->getProperties(ReflectionProperty::IS_PUBLIC),
            static fn (ReflectionProperty $p): bool => !$p->isStatic() && $p->getAttributes(Transient::class) === [],
        ));
    }
}
