<?php

declare(strict_types=1);

namespace Wdc\Framework\Domain\Criteria;

/**
 * Criterion on an ordered field — number, date, timestamp.
 *
 * @template-covariant C of object
 * @template T
 * @extends Criterion<C, T>
 */
class ComparableCriterion extends Criterion
{
    /**
     * @param T|null $value
     * @return C
     */
    public function gt(mixed $value): object
    {
        return $value === null ? $this->owner() : $this->add(Operator::GT, $value);
    }

    /**
     * @param T|null $value
     * @return C
     */
    public function ge(mixed $value): object
    {
        return $value === null ? $this->owner() : $this->add(Operator::GE, $value);
    }

    /**
     * @param T|null $value
     * @return C
     */
    public function lt(mixed $value): object
    {
        return $value === null ? $this->owner() : $this->add(Operator::LT, $value);
    }

    /**
     * @param T|null $value
     * @return C
     */
    public function le(mixed $value): object
    {
        return $value === null ? $this->owner() : $this->add(Operator::LE, $value);
    }

    /**
     * Closed interval. Accepts an open bound: start only ⇒ ge; end only ⇒ le; neither ⇒ nothing.
     *
     * @param T|null $start
     * @param T|null $end
     * @return C
     */
    public function between(mixed $start, mixed $end): object
    {
        if ($start === null && $end === null) {
            return $this->owner();
        }
        if ($start === null) {
            return $this->le($end);
        }
        if ($end === null) {
            return $this->ge($start);
        }

        return $this->add(Operator::BETWEEN, $start, $end);
    }
}
