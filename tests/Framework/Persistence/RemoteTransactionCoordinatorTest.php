<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Domain\Exception\AccessDeniedException;
use Wdc\Framework\Domain\Exception\TransactionConflictException;
use Wdc\Framework\Domain\Exception\TransactionLimitExceededException;
use Wdc\Framework\Persistence\Transaction\SqlRemoteTransactionCoordinator;
use Wdc\Framework\Persistence\Transaction\RemoteTransactionOptions;
use Wdc\Framework\Persistence\Transaction\TransactionSystemException;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Shopping\Persistence\ShoppingRepositories;
use Wdc\Tests\Support\TestDatabase;

use function Amp\async;

final class RemoteTransactionCoordinatorTest extends TestCase
{
    private const string OWNER = 'user:1';

    private ShoppingRepositories $repos;

    private float $now = 1_000_000.0;

    protected function setUp(): void
    {
        $this->repos = TestDatabase::repositories();
    }

    protected function tearDown(): void
    {
        $this->repos->db->bind(null);
        foreach ($this->coordinators as $c) {
            $c->close();   // transação esquecida aberta seguraria travas e o reset do próximo teste esperaria
        }
    }

    /** @var list<SqlRemoteTransactionCoordinator> */
    private array $coordinators = [];

    private function coordinator(RemoteTransactionOptions $options = new RemoteTransactionOptions()): SqlRemoteTransactionCoordinator
    {
        return $this->coordinators[] = new SqlRemoteTransactionCoordinator($this->repos->db, $options, fn (): float => $this->now);
    }

    /** Uma "requisição" com o txId: liga a transação, grava, desliga. */
    private function insertIn(SqlRemoteTransactionCoordinator $c, string $txId, string $name, string $owner = self::OWNER): void
    {
        $c->resume($txId, $owner);
        try {
            $this->repos->products->insert((new Product())->withName($name)->withPrice(1.0)->withDescription('-'));
        } finally {
            $c->suspend($txId);
        }
    }

    private function productsNamed(string $name): int
    {
        return $this->repos->products->count((new ProductCriteria())->withName($name));
    }

    #[Test]
    public function writesStayInsideTheTransaction_untilCommit(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);

        $this->insertIn($c, $txId, 'Remoto A');
        $this->insertIn($c, $txId, 'Remoto B');   // outra "requisição", mesma transação

        self::assertSame(0, $this->productsNamed('Remoto A'), 'a conexão principal não enxerga o que não foi commitado');
        self::assertSame('open', $c->status($txId, self::OWNER));

        $c->commit($txId, self::OWNER);

        self::assertSame(1, $this->productsNamed('Remoto A'));
        self::assertSame(1, $this->productsNamed('Remoto B'));
        self::assertSame('committed', $c->status($txId, self::OWNER));
        self::assertSame(0, $c->openCount());
    }

    #[Test]
    public function rollback_discardsEverything(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);
        $this->insertIn($c, $txId, 'Descartado');

        $c->rollback($txId, self::OWNER);

        self::assertSame(0, $this->productsNamed('Descartado'));
        self::assertSame('rolledback', $c->status($txId, self::OWNER));
    }

    #[Test]
    public function suspend_unbindsTheTransactionFromTheFiber(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);

        $c->resume($txId, self::OWNER);
        self::assertTrue($this->repos->db->inTransaction());
        $c->suspend($txId);

        self::assertFalse($this->repos->db->inTransaction());
        $c->rollback($txId, self::OWNER);
    }

    /** A transação é da Fiber que a retomou: as outras, ao mesmo tempo, seguem fora dela. */
    #[Test]
    public function otherFibers_doNotRunInsideTheTransaction(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);

        $c->resume($txId, self::OWNER);
        try {
            $this->repos->products->insert((new Product())->withName('Só na tx')->withPrice(1.0)->withDescription('-'));
            $outside = async(fn () => $this->productsNamed('Só na tx'))->await();
            $inside = $this->productsNamed('Só na tx');
        } finally {
            $c->suspend($txId);
        }

        self::assertSame([1, 0], [$inside, $outside]);
        $c->commit($txId, self::OWNER);
        self::assertSame(1, $this->productsNamed('Só na tx'));
    }

    #[Test]
    public function localTransaction_insideARemoteOne_participates(): void
    {
        $c = $this->coordinator();
        $txId = $c->begin(self::OWNER);
        $c->resume($txId, self::OWNER);
        $this->repos->transactions->required(function (): void {
            $this->repos->products->insert((new Product())->withName('Aninhado')->withPrice(1.0)->withDescription('-'));
        });
        $c->suspend($txId);

        self::assertSame(0, $this->productsNamed('Aninhado'), 'o required interno não commitou por conta própria');
        $c->commit($txId, self::OWNER);
        self::assertSame(1, $this->productsNamed('Aninhado'));
    }

    #[Test]
    public function commitAndRollback_areIdempotent_butRefuseTheOppositeOutcome(): void
    {
        $c = $this->coordinator();
        $committed = $c->begin(self::OWNER);
        $c->commit($committed, self::OWNER);
        $c->commit($committed, self::OWNER);   // resposta perdida, cliente repete: no-op

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
        self::assertSame('unknown', $c->status('nao-existe', self::OWNER));

        $this->expectException(TransactionSystemException::class);
        $c->resume('nao-existe', self::OWNER);
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
                self::fail('deveria negar');
            } catch (AccessDeniedException) {
                // esperado
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
            self::fail('teto por dono');
        } catch (TransactionLimitExceededException $e) {
            self::assertStringContainsString('por dono', $e->getMessage());
        }
        $c->begin('user:2');
        $this->expectException(TransactionLimitExceededException::class);
        $this->expectExceptionMessage('global');
        $c->begin('user:3');
    }

    #[Test]
    public function idleAndOldTransactions_areRolledBack_onTheNextBegin(): void
    {
        $c = $this->coordinator(new RemoteTransactionOptions(idleTimeoutMs: 1_000, maxLifetimeMs: 10_000));
        $idle = $c->begin(self::OWNER);
        $this->insertIn($c, $idle, 'Abandonado');
        $busy = $c->begin(self::OWNER);

        // a "ocupada" é usada a cada 900 ms: nunca fica ociosa, mas passa da vida máxima
        for ($i = 0; $i < 12; $i++) {
            $this->now += 900;
            $c->resume($busy, self::OWNER);
            $c->suspend($busy);
            if ($i === 1) {
                $c->begin('user:9');   // a varredura roda no begin
                self::assertSame('rolledback', $c->status($idle, self::OWNER), 'ociosa além do timeout');
                self::assertSame('open', $c->status($busy, self::OWNER));
            }
        }
        $c->begin('user:9');

        self::assertSame('rolledback', $c->status($busy, self::OWNER), 'passou da vida máxima');
        self::assertFalse($c->hasOpenTransactionForOwner(self::OWNER));
        self::assertSame(0, $this->productsNamed('Abandonado'));
    }

    #[Test]
    public function outcomes_areForgotten_afterTheRetentionWindow(): void
    {
        $c = $this->coordinator(new RemoteTransactionOptions(outcomeRetentionMs: 5_000));
        $txId = $c->begin(self::OWNER);
        $c->commit($txId, self::OWNER);

        $this->now += 6_000;
        $c->rollback($c->begin(self::OWNER), self::OWNER);   // begin varre

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
