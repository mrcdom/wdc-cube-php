<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence;

use Wdc\Framework\Domain\Transaction\TransactionService;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Persistence\PdoTransactionService;
use Wdc\Shopping\Persistence\Repository\ProductRepositoryImpl;
use Wdc\Shopping\Persistence\Repository\PurchaseItemRepositoryImpl;
use Wdc\Shopping\Persistence\Repository\PurchaseRepositoryImpl;
use Wdc\Shopping\Persistence\Repository\UserRepositoryImpl;

/**
 * Composition root da persistência: cria os repositórios e o serviço de transação
 * sobre uma mesma conexão.
 *
 * É um objeto comum passado adiante, sem estado global (nada de holders estáticos), o que deixa cada teste com o seu banco isolado.
 */
final class ShoppingRepositories
{
    public readonly UserRepositoryImpl $users;
    public readonly ProductRepositoryImpl $products;
    public readonly PurchaseRepositoryImpl $purchases;
    public readonly PurchaseItemRepositoryImpl $purchaseItems;
    public readonly TransactionService $transactions;

    public function __construct(public readonly Database $db)
    {
        $this->users = new UserRepositoryImpl($db);
        $this->products = new ProductRepositoryImpl($db);
        // compra ⇄ item se referenciam: recebem o agregado e o consultam quando precisam
        $this->purchases = new PurchaseRepositoryImpl($db, $this);
        $this->purchaseItems = new PurchaseItemRepositoryImpl($db, $this);
        $this->transactions = new PdoTransactionService($db);
    }

    /** Abre o banco pelo DSN do PDO e cria o schema e o seed se ainda não existirem. */
    public static function open(string $dsn, ?string $user = null, ?string $password = null): self
    {
        $db = Database::open($dsn, $user, $password);
        ShoppingSchema::create($db);

        return new self($db);
    }

    /** Abre (ou cria) o banco SQLite; cria o schema e o seed se ainda não existirem. */
    public static function openSqlite(string $path, bool $reset = false): self
    {
        $db = Database::sqlite($path);
        if (!ShoppingSchema::create($db) && $reset) {
            ShoppingSchema::reset($db);
        }

        return new self($db);
    }
}
