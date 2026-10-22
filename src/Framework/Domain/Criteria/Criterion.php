<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Critério sobre um campo: os pedidos de comparação feitos e como eles se combinam.
 *
 * Regras:
 * - pedidos sucessivos acumulam e valem juntos (AND); or() torna o campo disjuntivo (OR dentro do campo);
 * - valor null não acrescenta pedido (filtro de tela vazio = "não filtrar");
 * - os métodos fluentes devolvem o dono (o XxxCriteria) para encadear.
 *
 * @template-covariant C of object  tipo do XxxCriteria dono (covariante: só aparece em retornos)
 * @template T            tipo do valor comparado
 */
class Criterion
{
    /** @var list<Predicate<T>> */
    private array $predicates = [];

    private bool $disjunctive = false;

    /**
     * @param C $owner
     */
    public function __construct(
        private readonly object $owner,
        private readonly string $name,
    ) {
    }

    /** Nome lógico do campo. */
    public function name(): string
    {
        return $this->name;
    }

    /** @return list<Predicate<T>> */
    public function predicates(): array
    {
        return $this->predicates;
    }

    public function disjunctive(): bool
    {
        return $this->disjunctive;
    }

    /** true quando há ao menos um pedido, ou seja, quando o campo deve virar condição. */
    public function isSet(): bool
    {
        return $this->predicates !== [];
    }

    /** Marca o campo como disjuntivo: os pedidos passam a valer com OR entre si. */
    public function or(): static
    {
        $this->disjunctive = true;

        return $this;
    }

    /** Volta a exigir todos os pedidos (AND). */
    public function and(): static
    {
        $this->disjunctive = false;

        return $this;
    }

    /**
     * Apaga os pedidos deste campo. Não desfaz or().
     *
     * @return C
     */
    public function clear(): object
    {
        $this->predicates = [];

        return $this->owner;
    }

    /**
     * @param T|null $value
     * @return C
     */
    public function eq(mixed $value): object
    {
        return $value === null ? $this->owner : $this->add(Operator::EQ, $value);
    }

    /**
     * @param T|null $value
     * @return C
     */
    public function ne(mixed $value): object
    {
        return $value === null ? $this->owner : $this->add(Operator::NE, $value);
    }

    /**
     * Pertence ao conjunto. Conjunto nulo ou vazio não acrescenta pedido.
     *
     * @param iterable<T>|null $values
     * @return C
     */
    public function in(?iterable $values): object
    {
        if ($values === null) {
            return $this->owner;
        }
        $list = [];
        foreach ($values as $v) {
            $list[] = $v;
        }
        if ($list === []) {
            return $this->owner;
        }

        return $this->add(Operator::IN, ...$list);
    }

    /** @return C */
    public function isNull(): object
    {
        return $this->add(Operator::IS_NULL);
    }

    /** @return C */
    public function isNotNull(): object
    {
        return $this->add(Operator::IS_NOT_NULL);
    }

    /**
     * Repõe um pedido tal como veio do transporte, sem as regras dos métodos fluentes.
     *
     * Os valores não são conferidos contra T: quem lê o transporte (CriterionCodec) já os
     * converteu pelo tipo declarado do campo.
     *
     * @param list<mixed> $values
     */
    public function restore(Operator $operator, array $values): void
    {
        $this->predicates[] = new Predicate($operator, $values);
    }

    /**
     * @param T ...$values
     * @return C
     */
    protected function add(Operator $operator, mixed ...$values): object
    {
        $this->predicates[] = new Predicate($operator, array_values($values));

        return $this->owner;
    }

    /** @return C */
    protected function owner(): object
    {
        return $this->owner;
    }
}
