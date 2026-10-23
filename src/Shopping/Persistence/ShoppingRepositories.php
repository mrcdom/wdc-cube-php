<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence;

use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\SqlTransactionService;
use Wdc\Shopping\Domain\Product\ProductRepository;
use Wdc\Shopping\Domain\Purchase\PurchaseRepository;
use Wdc\Shopping\Domain\PurchaseItem\PurchaseItemRepository;
use Wdc\Shopping\Domain\User\UserRepository;
use Wdc\Shopping\Persistence\Repository\ProductRepositoryImpl;
use Wdc\Shopping\Persistence\Repository\PurchaseItemRepositoryImpl;
use Wdc\Shopping\Persistence\Repository\PurchaseRepositoryImpl;
use Wdc\Shopping\Persistence\Repository\UserRepositoryImpl;

/**
 * Montagem da persistência: cria os repositórios e o serviço de transação sobre o mesmo
 * `Database` e os registra no `Beans`, pela interface. Não guarda nada: daí em diante, qualquer
 * ponto (inclusive um repositório que precisa de outro) os acha com
 * `Beans::of(ProductRepository::class)`.
 *
 * Um repositório novo é uma linha aqui.
 */
final class ShoppingRepositories
{
    private function __construct()
    {
    }

    public static function register(Database $db): void
    {
        Beans::set(UserRepository::class, new UserRepositoryImpl($db));
        Beans::set(ProductRepository::class, new ProductRepositoryImpl($db));
        Beans::set(PurchaseRepository::class, new PurchaseRepositoryImpl($db));
        Beans::set(PurchaseItemRepository::class, new PurchaseItemRepositoryImpl($db));
        Beans::set(TransactionService::class, new SqlTransactionService($db));
    }

    /**
     * Abre o banco (criando-o, se preciso), cria o schema e o seed se ainda não existirem e
     * registra os repositórios.
     *
     * @param positive-int $maxConnections
     */
    public static function open(string $dsn, ?string $user = null, ?string $password = null, int $maxConnections = Database::DEFAULT_MAX_CONNECTIONS): Database
    {
        $db = Database::open($dsn, $user, $password, $maxConnections);
        ShoppingSchema::create($db);
        self::register($db);

        return $db;
    }
}
