<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Persistence;


use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Domain\Product\ProductRepository;
use Wdc\Tests\Shopping\Persistence\RepositoryTestCase;
use function Amp\Future\await;
use function Amp\async;

/**
 * Database access does not block the event loop: a slow query suspends only the Fiber that issued
 * it, and queries from other Fibers (other sessions) proceed — even in parallel in the database.
 */
final class DatabaseConcurrencyTest extends RepositoryTestCase
{
    #[Test]
    public function aSlowQuery_doesNotDelayTheOthers(): void
    {
        $slow = async(fn () => $this->db->fetchValue('SELECT pg_sleep(0.6)'));

        $start = microtime(true);
        $count = Beans::of(ProductRepository::class)->count(new ProductCriteria());
        $quick = microtime(true) - $start;
        $slow->await();

        self::assertSame(4, $count);
        self::assertLessThan(0.3, $quick, 'the quick query did not wait for the slow one');
    }

    #[Test]
    public function queriesFromDifferentFibers_runInParallel(): void
    {
        $start = microtime(true);
        await([
            async(fn () => $this->db->fetchValue('SELECT pg_sleep(0.4)')),
            async(fn () => $this->db->fetchValue('SELECT pg_sleep(0.4)')),
            async(fn () => $this->db->fetchValue('SELECT pg_sleep(0.4)')),
        ]);

        self::assertLessThan(0.9, microtime(true) - $start, 'three 0.4 s queries at once, not 1.2 s in sequence');
    }

    /** The transaction belongs to the Fiber that opened it: what another Fiber writes meanwhile stays outside. */
    #[Test]
    public function aTransaction_doesNotCaptureOtherFibersWrites(): void
    {
        $inside = async(function (): void {
            try {
                Beans::of(TransactionService::class)->required(function (): void {
                    $this->insert('Inside');
                    $this->db->fetchValue('SELECT pg_sleep(0.3)');   // yields in the middle of the transaction
                    throw new RuntimeException('undo');
                });
            } catch (RuntimeException) {
                // expected: the transaction is rolled back
            }
        });
        $outside = async(fn () => $this->insert('Outside'));
        await([$inside, $outside]);

        self::assertSame(0, $this->named('Inside'), 'rolled back with the transaction');
        self::assertSame(1, $this->named('Outside'), 'written outside it, despite running meanwhile');
    }

    private function insert(string $name): void
    {
        Beans::of(ProductRepository::class)->insert((new Product())->withName($name)->withPrice(1.0)->withDescription('-'));
    }

    private function named(string $name): int
    {
        return Beans::of(ProductRepository::class)->count((new ProductCriteria())->withName($name));
    }
}
