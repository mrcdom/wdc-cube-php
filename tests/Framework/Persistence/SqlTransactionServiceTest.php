<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Persistence;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Transaction\TransactionContext;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Store\Domain\Product\Product;
use Wdc\Store\Domain\Product\ProductCriteria;
use Wdc\Store\Domain\Product\ProductRepository;
use Wdc\Tests\Store\Persistence\RepositoryTestCase;

final class SqlTransactionServiceTest extends RepositoryTestCase
{
    private function insertProduct(string $name): void
    {
        Beans::of(ProductRepository::class)->insert(new Product()->withName($name)->withPrice(1.0)->withDescription('-'));
    }

    private function products(): int
    {
        return Beans::of(ProductRepository::class)->count(new ProductCriteria());
    }

    #[Test]
    public function commit_onNormalReturn_andReturnsTheValue(): void
    {
        $result = Beans::of(TransactionService::class)->required(function (): string {
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
            Beans::of(TransactionService::class)->required(function (): void {
                $this->insertProduct('A');
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        self::assertSame('failed', $caught->getMessage(), 'the exception should have been rethrown');
        self::assertSame(4, $this->products());
    }

    #[Test]
    public function rollback_whenMarkedRollbackOnly(): void
    {
        Beans::of(TransactionService::class)->required(function (TransactionContext $tx): void {
            $this->insertProduct('A');
            $tx->setRollbackOnly();
        });

        self::assertSame(4, $this->products());
    }

    #[Test]
    public function nested_participatesInTheOuterTransaction(): void
    {
        $tx = Beans::of(TransactionService::class);
        $tx->required(function (TransactionContext $outer) use ($tx): void {   // `use` captures outer variables
            $this->insertProduct('A');
            $tx->required(function (TransactionContext $inner) use ($outer): void {
                self::assertSame($outer, $inner, 'the inner one participates in the same transaction');
                $this->insertProduct('B');
            });
            $outer->setRollbackOnly();
        });

        self::assertSame(4, $this->products(), 'rolling back the outer one also undoes the inner one\'s work');
    }

    #[Test]
    public function nested_exception_marksRollbackOnly(): void
    {
        $tx = Beans::of(TransactionService::class);
        $tx->required(function (TransactionContext $outer) use ($tx): void {
            $this->insertProduct('A');
            try {
                $tx->required(static fn () => throw new RuntimeException('inner'));
            } catch (RuntimeException) {
                // swallowed on purpose: the outer one continues, but is already doomed
            }
            self::assertTrue($outer->isRollbackOnly());
        });

        self::assertSame(4, $this->products());
    }

    #[Test]
    public function context_isInactiveAfterwards(): void
    {
        $context = Beans::of(TransactionService::class)->required(static fn (TransactionContext $tx): TransactionContext => $tx);
        self::assertFalse($context->isActive());
    }
}
