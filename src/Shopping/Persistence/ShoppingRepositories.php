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
 * Persistence wiring: creates the repositories and the transaction service over the same
 * `Database` and registers them in `Beans`, by interface. It keeps nothing: from then on, any
 * code (including a repository that needs another one) finds them with
 * `Beans::of(ProductRepository::class)`.
 *
 * A new repository is one line here.
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
     * Opens the database (creating it if needed), creates the schema and the seed if they do not
     * exist yet, and registers the repositories.
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
