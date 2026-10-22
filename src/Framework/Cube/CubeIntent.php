<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

use Stringable;
use Wdc\Framework\Cube\Util\QueryString;

/**
 * "Vá para este place com estes parâmetros". Mapeia um-para-um com a URL.
 *
 * - **parameters**: vão para a URL. A ordem de inserção importa (entra na assinatura da URL).
 *   Um array PHP já preserva a ordem de inserção, então serve direto como mapa ordenado.
 * - **attributes**: transientes, nunca vão para a URL (slots de view, flags entre presenters).
 *
 * PHP não tem sobrecarga de métodos: há um único `setParameter(mixed)` e um getter por tipo
 * de retorno.
 */
final class CubeIntent implements Stringable
{
    private ?CubePlace $place = null;

    /** @var array<string, mixed> */
    private array $parameters = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** "product?productId=3" → intent com GenericPlace('product') e o parâmetro (como string). */
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

    /** Remove o atributo e devolve o valor que ele tinha (ou null). */
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
     * Define ou substitui um parâmetro. null remove.
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

    /** Parâmetro como inteiro (int do PHP tem 64 bits, cobre também valores longos). */
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

    /** "product" ou "product?productId=3" — sem barra inicial, como o protocolo espera. */
    public function __toString(): string
    {
        $name = $this->place?->name() ?? '';
        $query = $this->queryString();

        return $query === '' ? $name : "{$name}?{$query}";
    }

    /** Parâmetro multivalorado ⇒ o primeiro valor. */
    private function first(string $name): mixed
    {
        $value = $this->parameters[$name] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
