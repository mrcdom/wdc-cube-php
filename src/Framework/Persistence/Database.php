<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Amp\Postgres\PostgresByteA;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnection;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresExecutor;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlConnectionException;
use Closure;
use Throwable;
use Wdc\Framework\Commons\Concurrent\FiberValue;
use Wdc\Framework\Persistence\Dialect\PostgresDialect;
use Wdc\Framework\Persistence\Dialect\SqlDialect;

use function Amp\Postgres\connect;

/**
 * Asynchronous PostgreSQL access over a connection pool (`amphp/postgres`).
 *
 * A query suspends only the Fiber that issued it: while the database responds, the event loop
 * keeps serving the other sessions, and several queries run at once, each on a pool connection.
 * For the caller nothing changes: `fetchAll()` returns the rows, like an ordinary call.
 *
 * **Transaction = exclusive connection.** Outside a transaction, each command takes any free
 * pool connection. Inside one, all commands must go to the same connection — and only they may.
 * That is why the transaction is "bound" to the current Fiber ({@see bind()}): that Fiber's SQL
 * goes to the transaction; the other Fibers' SQL keeps going to the pool.
 */
final class Database
{
    public const int DEFAULT_MAX_CONNECTIONS = 16;

    /** @var (Closure(string, list<mixed>): void)|null */
    private ?Closure $listener = null;

    private readonly SqlDialect $dialect;

    private readonly PostgresConnectionPool $pool;

    /** @var FiberValue<PostgresTransaction> the transaction bound to the current Fiber */
    private readonly FiberValue $bound;

    public function __construct(
        private readonly PostgresConfig $config,
        int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
    ) {
        $this->dialect = new PostgresDialect();
        // resetConnections: false — the project keeps no session state on the connection, and
        // a DISCARD ALL before each use would cost one database round trip per command
        $this->pool = new PostgresConnectionPool($config, max(1, $maxConnections), resetConnections: false);
        $this->bound = new FiberValue(PostgresTransaction::class);
    }

    /**
     * Opens by database address, in the form `host=localhost;port=5432;dbname=shop` (the `pgsql:`
     * prefix is accepted). If the database does not exist yet, tries to create it.
     *
     * @param positive-int $maxConnections
     */
    public static function open(
        string $dsn,
        ?string $user = null,
        ?string $password = null,
        int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
    ): self {
        $config = self::config($dsn, $user, $password);
        self::createDatabaseIfMissing($config);

        return new self($config, $maxConnections);
    }

    /**
     * @throws \InvalidArgumentException malformed address
     */
    public static function config(string $dsn, ?string $user = null, ?string $password = null): PostgresConfig
    {
        $dsn = str_starts_with($dsn, 'pgsql:') ? substr($dsn, strlen('pgsql:')) : $dsn;
        $config = PostgresConfig::fromString($dsn);
        if ($user !== null && $user !== '') {
            $config = $config->withUser($user);
        }
        if ($password !== null && $password !== '') {
            $config = $config->withPassword($password);
        }

        // dates always in UTC on every connection (applies as the session default from the start)
        return $config->withOptions('-c TimeZone=UTC');
    }

    public function driver(): string
    {
        return $this->dialect->name();
    }

    /** What differs from one database to another (sequences, pagination, JSON...). */
    public function dialect(): SqlDialect
    {
        return $this->dialect;
    }

    /**
     * Observes each command sent to the database (tests, debugging, SQL logging).
     *
     * @param (Closure(string, list<mixed>): void)|null $listener
     */
    public function onQuery(?Closure $listener): void
    {
        $this->listener = $listener;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = [];
        foreach ($this->run($sql, $params) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param list<mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        foreach ($this->run($sql, $params) as $row) {
            return array_values($row)[0] ?? null;
        }

        return null;
    }

    /**
     * @param list<mixed> $params
     * @return int affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->getRowCount() ?? 0;
    }

    /** Runs several commands without parameters (DDL). */
    public function exec(string $sql): void
    {
        if ($this->listener !== null) {
            ($this->listener)($sql, []);
        }
        $result = $this->executor()->query($sql);
        while ($result !== null) {   // one result per command: consume them all
            $result = $result->getNextResult();
        }
    }

    public function nextval(string $sequence): int
    {
        return $this->dialect->nextval($this, $sequence);
    }

    public function restartSequence(string $sequence, int $next): void
    {
        $this->dialect->restartSequence($this, $sequence, $next);
    }

    /** Opens a transaction on a pool connection (the connection stays tied to it until the end). */
    public function beginTransaction(): PostgresTransaction
    {
        return $this->pool->beginTransaction();
    }

    /**
     * A dedicated connection outside the pool — for the REST API remote transaction, which spans
     * several requests and must not hold a pool connection for all that time.
     */
    public function newConnection(): PostgresConnection
    {
        return connect($this->config);
    }

    /**
     * Binds a transaction to the current Fiber; null unbinds. From then on, this Fiber's SQL goes
     * to the transaction.
     *
     * @return PostgresTransaction|null the previously bound one
     */
    public function bind(?PostgresTransaction $transaction): ?PostgresTransaction
    {
        $previous = $this->bound->get();
        $this->bound->set($transaction);

        return $previous;
    }

    /** The transaction bound to the current Fiber, if any. */
    public function boundTransaction(): ?PostgresTransaction
    {
        return $this->bound->get();
    }

    public function inTransaction(): bool
    {
        return $this->bound->get() !== null;
    }

    /** Closes the pool (server or test shutdown). */
    public function close(): void
    {
        $this->pool->close();
    }

    /** Where SQL goes right now: this Fiber's transaction, or any pool connection. */
    private function executor(): PostgresExecutor
    {
        return $this->bound->get() ?? $this->pool;
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params): \Amp\Postgres\PostgresResult
    {
        if ($this->listener !== null) {
            ($this->listener)($sql, $params);
        }
        $values = array_map(static fn (mixed $value): mixed => match (true) {
            is_bool($value) => $value ? 1 : 0,
            $value instanceof Blob => new PostgresByteA($value->bytes),
            default => $value,
        }, $params);

        return $this->executor()->execute($sql, $values);
    }

    /**
     * Development convenience: if the database does not exist, creates it through the `postgres`
     * maintenance database. Without permission to do so (common in production), the error says
     * what to do.
     */
    private static function createDatabaseIfMissing(PostgresConfig $config): void
    {
        try {
            connect($config)->close();

            return;
        } catch (SqlConnectionException $e) {
            if (!str_contains($e->getMessage(), 'does not exist') || $config->getDatabase() === null) {
                throw $e;
            }
        }
        try {
            $admin = connect($config->withDatabase('postgres'));
            $admin->query('CREATE DATABASE ' . $admin->quoteIdentifier($config->getDatabase()));
            $admin->close();
        } catch (Throwable $e) {
            throw new SqlConnectionException(
                "Database '{$config->getDatabase()}' does not exist and could not be created: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
}
