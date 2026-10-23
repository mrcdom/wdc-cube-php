<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Persistence;


use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Exception\TransactionConflictException;
use Wdc\Framework\Domain\Exception\TransactionLimitExceededException;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Framework\Persistence\Transaction\RemoteTransactionOptions;
use Wdc\Framework\Persistence\Transaction\SqlRemoteTransactionCoordinator;
use Wdc\Framework\Persistence\Transaction\TransactionSystemException;
use Wdc\Store\Domain\Product\Product;
use Wdc\Store\Domain\Product\ProductCriteria;
use Wdc\Store\Domain\Product\ProductRepository;
use Wdc\Framework\Persistence\Database;
use Wdc\Tests\Support\TestDatabase;
use function Amp\async;

final class RemoteTransactionCoordinatorTest extends TestCase
{
    private const string OWNER = 'user:1';

    private Database $db;

    private float $now = 1_000_000.0;

    protected function setUp(): void
    {
        $this->db = TestDatabase::open();
    }

    protected function tearDown(): void
    {
        $this->db->bind(null);
        foreach ($this->coordinators as $c) {
            $c->close();   // a transaction left open would hold locks and the next test's reset would wait
        }
    }

    /** @var list<SqlRemoteTransactionCoordinator> */
    private array $coordinators = [];

    private function coordinator(RemoteTransactionOptions $options = new RemoteTransactionOptions()): SqlRemoteTransactionCoordinator
    {
        return $this->coordinators[] = new SqlRemoteTransactionCoordinator($this->db, $options, fn (): float => $this->now);
    }

    /** A "request" carrying the txId: binds the transaction, writes, unbinds. */
    private function insertIn(SqlRemoteTransactionCoordinator $c, string $txId, string $name, string $owner = self::OWNER): void
    {
        $c->resume($txId, $owner);
        try {
            Beans::of(ProductRepository::class)->insert((new Product())->withName($name)->withPrice(1.0)->withDescription('-'));
        } finally {
            $c->suspend($txId);
        }
    }

    private function productsNamed(string $name): int
    {
        return Beans::of(ProductRepository::class)->count((new ProductCriteria())->withName($name));
    }

    #[Test]
    public function writesStayInsideTheTransaction_untilCommit(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);

        $this->insertIn($c, $txId, 'Remote A');
        $this->insertIn($c, $txId, 'Remote B');   // another "request", same transaction

        self::assertSame(0, $this->productsNamed('Remote A'), 'the main connection does not see uncommitted data');
        self::assertSame('open', $c->status($txId, self::OWNER));

        $c->commit($txId, self::OWNER);

        self::assertSame(1, $this->productsNamed('Remote A'));
        self::assertSame(1, $this->productsNamed('Remote B'));
        self::assertSame('committed', $c->status($txId, self::OWNER));
        self::assertSame(0, $c->openCount());
    }

    #[Test]
    public function rollback_discardsEverything(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);
        $this->insertIn($c, $txId, 'Discarded');

        $c->rollback($txId, self::OWNER);

        self::assertSame(0, $this->productsNamed('Discarded'));
        self::assertSame('rolledback', $c->status($txId, self::OWNER));
    }

    #[Test]
    public function suspend_unbindsTheTransactionFromTheFiber(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);

        $c->resume($txId, self::OWNER);
        self::assertTrue($this->db->inTransaction());
        $c->suspend($txId);

        self::assertFalse($this->db->inTransaction());
        $c->rollback($txId, self::OWNER);
    }

    /** The transaction belongs to the Fiber that resumed it: the others, running at the same time, stay outside it. */
    #[Test]
    public function otherFibers_doNotRunInsideTheTransaction(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);

        $c->resume($txId, self::OWNER);
        try {
            Beans::of(ProductRepository::class)->insert((new Product())->withName('Only in tx')->withPrice(1.0)->withDescription('-'));
            $outside = async(fn () => $this->productsNamed('Only in tx'))->await();
            $inside = $this->productsNamed('Only in tx');
        } finally {
            $c->suspend($txId);
        }

        self::assertSame([1, 0], [$inside, $outside]);
        $c->commit($txId, self::OWNER);
        self::assertSame(1, $this->productsNamed('Only in tx'));
    }

    #[Test]
    public function localTransaction_insideARemoteOne_participates(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);
        $c->resume($txId, self::OWNER);
        Beans::of(TransactionService::class)->required(function (): void {
            Beans::of(ProductRepository::class)->insert((new Product())->withName('Nested')->withPrice(1.0)->withDescription('-'));
        });
        $c->suspend($txId);

        self::assertSame(0, $this->productsNamed('Nested'), 'the inner required did not commit on its own');
        $c->commit($txId, self::OWNER);
        self::assertSame(1, $this->productsNamed('Nested'));
    }

    #[Test]
    public function commitAndRollback_areIdempotent_butRefuseTheOppositeOutcome(): void
    {
        $c = $this->coordinator();
        $committed = $c->begin(self::OWNER);
        $c->commit($committed, self::OWNER);
        $c->commit($committed, self::OWNER);   // lost response, client retries: no-op

        $rolledBack = $c->begin(self::OWNER);
        $c->rollback($rolledBack, self::OWNER);
        $c->rollback($rolledBack, self::OWNER);

        $this->expectException(TransactionConflictException::class);
        $c->rollback($committed, self::OWNER);
    }

    #[Test]
    public function unknownTransaction_isAnError_andStatusSaysUnknown(): void
    {
        $c = $this->coordinator();
        self::assertSame('unknown', $c->status('does-not-exist', self::OWNER));

        $this->expectException(TransactionSystemException::class);
        $c->resume('does-not-exist', self::OWNER);
    }

    #[Test]
    public function anotherOwner_cannotUseTheTransaction(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);
        foreach ([
            fn () => $c->resume($txId, 'user:2'),
            fn () => $c->commit($txId, 'user:2'),
            fn () => $c->status($txId, null),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('should have been denied');
            } catch (AccessDeniedException) {
                // expected
            }
        }
        self::assertSame('open', $c->status($txId, self::OWNER));
        $c->rollback($txId, self::OWNER);
    }

    #[Test]
    public function concurrentUse_ofTheSameTransaction_isRefused(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);
        $c->resume($txId, self::OWNER);

        try {
            $this->expectException(TransactionSystemException::class);
            $c->resume($txId, self::OWNER);
        } finally {
            $c->suspend($txId);
            $c->rollback($txId, self::OWNER);
        }
    }

    #[Test]
    public function limits_perOwnerAndGlobal(): void
    {
        $c = $this->coordinator(new RemoteTransactionOptions(maxOpen: 3, maxOpenPerOwner: 2));
        $c->begin('user:1');
        $c->begin('user:1');
        try {
            $c->begin('user:1');
            self::fail('per-owner limit');
        } catch (TransactionLimitExceededException $e) {
            self::assertStringContainsString('Per-owner', $e->getMessage());
        }
        $c->begin('user:2');
        $this->expectException(TransactionLimitExceededException::class);
        $this->expectExceptionMessage('Global');
        $c->begin('user:3');
    }

    #[Test]
    public function idleAndOldTransactions_areRolledBack_onTheNextBegin(): void
    {
        $c = $this->coordinator(new RemoteTransactionOptions(idleTimeoutMs: 1_000, maxLifetimeMs: 10_000));
        $idle = $c->begin(self::OWNER);
        $this->insertIn($c, $idle, 'Abandoned');
        $busy = $c->begin(self::OWNER);

        // the "busy" one is used every 900 ms: never idle, but it exceeds the maximum lifetime
        for ($i = 0; $i < 12; $i++) {
            $this->now += 900;
            $c->resume($busy, self::OWNER);
            $c->suspend($busy);
            if ($i === 1) {
                $c->begin('user:9');   // the sweep runs on begin
                self::assertSame('rolledback', $c->status($idle, self::OWNER), 'idle beyond the timeout');
                self::assertSame('open', $c->status($busy, self::OWNER));
            }
        }
        $c->begin('user:9');

        self::assertSame('rolledback', $c->status($busy, self::OWNER), 'exceeded the maximum lifetime');
        self::assertFalse($c->hasOpenTransactionForOwner(self::OWNER));
        self::assertSame(0, $this->productsNamed('Abandoned'));
    }

    #[Test]
    public function outcomes_areForgotten_afterTheRetentionWindow(): void
    {
        $c = $this->coordinator(new RemoteTransactionOptions(outcomeRetentionMs: 5_000));
        $txId = $c->begin(self::OWNER);
        $c->commit($txId, self::OWNER);

        $this->now += 6_000;
        $c->rollback($c->begin(self::OWNER), self::OWNER);   // begin sweeps

        self::assertSame('unknown', $c->status($txId, self::OWNER));
    }

    #[Test]
    public function ownerIndex_tracksOpenTransactions(): void
    {
        $c = $this->coordinator();
        $a = $c->begin(self::OWNER);
        $b = $c->begin(self::OWNER);
        $c->commit($a, self::OWNER);
        self::assertTrue($c->hasOpenTransactionForOwner(self::OWNER));
        $c->rollback($b, self::OWNER);
        self::assertFalse($c->hasOpenTransactionForOwner(self::OWNER));
        self::assertFalse($c->hasOpenTransactionForOwner(null));
    }
}
