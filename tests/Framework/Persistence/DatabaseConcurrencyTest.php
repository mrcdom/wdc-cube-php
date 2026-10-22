<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Persistence;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Wdc\Shopping\Domain\Product\Product;
use Wdc\Shopping\Domain\Product\ProductCriteria;
use Wdc\Tests\Shopping\Persistence\RepositoryTestCase;

use function Amp\async;
use function Amp\Future\await;

/**
 * O acesso ao banco não bloqueia o event loop: uma consulta lenta suspende só a Fiber que a fez,
 * e as consultas de outras Fibers (outras sessões) seguem — inclusive em paralelo no banco.
 */
final class DatabaseConcurrencyTest extends RepositoryTestCase
{
    #[Test]
    public function aSlowQuery_doesNotDelayTheOthers(): void
    {
        $slow = async(fn () => $this->repos->db->fetchValue('SELECT pg_sleep(0.6)'));

        $start = microtime(true);
        $count = $this->repos->products->count(new ProductCriteria());
        $quick = microtime(true) - $start;
        $slow->await();

        self::assertSame(4, $count);
        self::assertLessThan(0.3, $quick, 'a consulta rápida não esperou a lenta');
    }

    #[Test]
    public function queriesFromDifferentFibers_runInParallel(): void
    {
        $start = microtime(true);
        await([
            async(fn () => $this->repos->db->fetchValue('SELECT pg_sleep(0.4)')),
            async(fn () => $this->repos->db->fetchValue('SELECT pg_sleep(0.4)')),
            async(fn () => $this->repos->db->fetchValue('SELECT pg_sleep(0.4)')),
        ]);

        self::assertLessThan(0.9, microtime(true) - $start, 'três de 0,4 s ao mesmo tempo, não 1,2 s em fila');
    }

    /** A transação é da Fiber que a abriu: o que outra Fiber grava no meio dela fica de fora. */
    #[Test]
    public function aTransaction_doesNotCaptureOtherFibersWrites(): void
    {
        $inside = async(function (): void {
            try {
                $this->repos->transactions->required(function (): void {
                    $this->insert('Dentro');
                    $this->repos->db->fetchValue('SELECT pg_sleep(0.3)');   // cede a vez no meio da transação
                    throw new RuntimeException('desfaz');
                });
            } catch (RuntimeException) {
                // esperado: a transação é revertida
            }
        });
        $outside = async(fn () => $this->insert('Fora'));
        await([$inside, $outside]);

        self::assertSame(0, $this->named('Dentro'), 'revertido com a transação');
        self::assertSame(1, $this->named('Fora'), 'gravado fora dela, apesar de rodar durante');
    }

    private function insert(string $name): void
    {
        $this->repos->products->insert((new Product())->withName($name)->withPrice(1.0)->withDescription('-'));
    }

    private function named(string $name): int
    {
        return $this->repos->products->count((new ProductCriteria())->withName($name));
    }
}
