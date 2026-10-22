<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Persistence;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Wdc\Framework\Domain\Transaction\TransactionContext;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Tests\Shopping\Persistence\RepositoryTestCase;

final class PdoTransactionServiceTest extends RepositoryTestCase
{
    private function insertProduct(string $name): void
    {
        $this->repos->products->insert(new Product()->withName($name)->withPrice(1.0)->withDescription('-'));
    }

    private function products(): int
    {
        return $this->repos->products->count(new ProductCriteria());
    }

    #[Test]
    public function commit_onNormalReturn_andReturnsTheValue(): void
    {
        $result = $this->repos->transactions->required(function (): string {
            $this->insertProduct('A');

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(5, $this->products());
    }

    #[Test]
    public function rollback_onException_rethrows(): void
    {
        $caught = null;
        try {
            $this->repos->transactions->required(function (): void {
                $this->insertProduct('A');
                throw new RuntimeException('falhou');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        self::assertSame('falhou', $caught->getMessage(), 'a exceção deveria ser repropagada');
        self::assertSame(4, $this->products());
    }

    #[Test]
    public function rollback_whenMarkedRollbackOnly(): void
    {
        $this->repos->transactions->required(function (TransactionContext $tx): void {
            $this->insertProduct('A');
            $tx->setRollbackOnly();
        });

        self::assertSame(4, $this->products());
    }

    #[Test]
    public function nested_participatesInTheOuterTransaction(): void
    {
        $tx = $this->repos->transactions;
        $tx->required(function (TransactionContext $outer) use ($tx): void {   // `use` captura variáveis de fora
            $this->insertProduct('A');
            $tx->required(function (TransactionContext $inner) use ($outer): void {
                self::assertSame($outer, $inner, 'a interna participa da mesma transação');
                $this->insertProduct('B');
            });
            $outer->setRollbackOnly();
        });

        self::assertSame(4, $this->products(), 'rollback da externa desfaz também o trabalho da interna');
    }

    #[Test]
    public function nested_exception_marksRollbackOnly(): void
    {
        $tx = $this->repos->transactions;
        $tx->required(function (TransactionContext $outer) use ($tx): void {
            $this->insertProduct('A');
            try {
                $tx->required(static fn () => throw new RuntimeException('interna'));
            } catch (RuntimeException) {
                // engolida de propósito: a externa segue, mas já está condenada
            }
            self::assertTrue($outer->isRollbackOnly());
        });

        self::assertSame(4, $this->products());
    }

    #[Test]
    public function context_isInactiveAfterwards(): void
    {
        $context = $this->repos->transactions->required(static fn (TransactionContext $tx): TransactionContext => $tx);
        self::assertFalse($context->isActive());
    }
}
