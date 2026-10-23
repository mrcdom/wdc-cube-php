<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use Stringable;
use Wdc\Framework\Cube\Util\QueryString;

/**
 * "Go to this place with these parameters". Maps one-to-one to the URL.
 *
 * - **parameters**: go into the URL. Insertion order matters (it is part of the URL signature).
 *   A PHP array already preserves insertion order, so it works directly as an ordered map.
 * - **attributes**: transient, never go into the URL (view slots, flags between presenters).
 *
 * PHP has no method overloading: there is a single `setParameter(mixed)` and one getter per
 * return type.
 */
final class CubeIntent implements Stringable
{
    private ?CubePlace $place = null;

    /** @var array<string, mixed> */
    private array $parameters = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** "product?pr-id=3" → intent with GenericPlace('product') and the parameter (as a string). */
    public static function parse(?string $uri): self
    {
        $intent = new self();
        if ($uri === null || trim($uri) === '') {
            $intent->setPlace(new GenericPlace('unknown'));

            return $intent;
        }

        $parts = explode('?', $uri, 2);
        $intent->setPlace(new GenericPlace($parts[0]));
        foreach (QueryString::parse($parts[1] ?? null) as $name => $value) {
            $intent->setParameter($name, $value);
        }

        return $intent;
    }

    public function place(): ?CubePlace
    {
        return $this->place;
    }

    public function setPlace(?CubePlace $place): void
    {
        $this->place = $place;
    }

    // :: Attributes

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /** Removes the attribute and returns the value it had (or null). */
    public function removeAttribute(string $name): mixed
    {
        $value = $this->attributes[$name] ?? null;
        unset($this->attributes[$name]);

        return $value;
    }

    public function setViewSlot(string $name, ?CubeViewSlot $slot): void
    {
        $this->setAttribute($name, $slot);
    }

    public function getViewSlot(string $name): ?CubeViewSlot
    {
        $value = $this->getAttribute($name);

        return $value instanceof CubeViewSlot ? $value : null;
    }

    // :: Parameters

    /**
     * Sets or replaces a parameter. null removes it.
     *
     * @param scalar|list<scalar>|null $value
     */
    public function setParameter(string $name, mixed $value): void
    {
        if ($value === null) {
            unset($this->parameters[$name]);
        } else {
            $this->parameters[$name] = $value;
        }
    }

    public function removeParameter(string $name): mixed
    {
        $value = $this->parameters[$name] ?? null;
        unset($this->parameters[$name]);

        return $value;
    }

    public function clearParameters(): void
    {
        $this->parameters = [];
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function getParameterValue(string $name): mixed
    {
        return $this->parameters[$name] ?? null;
    }

    public function getParameterAsString(string $name, ?string $default = null): ?string
    {
        return QueryString::asString($this->first($name)) ?? $default;
    }

    /** Parameter as an integer (PHP's int is 64 bits, so it also covers long values). */
    public function getParameterAsInt(string $name, ?int $default = null): ?int
    {
        $value = $this->first($name);
        if (is_int($value)) {
            return $value;
        }
        $int = filter_var(QueryString::asString($value), FILTER_VALIDATE_INT);   // "42" → 42; "4x" → false

        return $int === false ? $default : $int;
    }

    public function getParameterAsFloat(string $name, ?float $default = null): ?float
    {
        $value = $this->first($name);
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $float = filter_var(QueryString::asString($value), FILTER_VALIDATE_FLOAT);

        return $float === false ? $default : $float;
    }

    public function getParameterAsBool(string $name, ?bool $default = null): ?bool
    {
        $value = $this->first($name);
        if (is_bool($value)) {
            return $value;
        }
        $text = QueryString::asString($value);

        return $text === null ? $default : strtolower($text) === 'true';
    }

    public function queryString(): string
    {
        return QueryString::build($this->parameters);
    }

    /** "product" or "product?pr-id=3" — no leading slash, as the protocol expects. */
    public function __toString(): string
    {
        $name = $this->place?->name() ?? '';
        $query = $this->queryString();

        return $query === '' ? $name : "{$name}?{$query}";
    }

    /** Multi-valued parameter ⇒ the first value. */
    private function first(string $name): mixed
    {
        $value = $this->parameters[$name] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
