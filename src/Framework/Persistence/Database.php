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
 * Acesso ao PostgreSQL, assíncrono, sobre um pool de conexões (`amphp/postgres`).
 *
 * Uma consulta suspende só a Fiber que a fez: enquanto o banco responde, o event loop segue
 * atendendo as outras sessões, e várias consultas rodam ao mesmo tempo, cada uma numa conexão
 * do pool. Para quem chama, nada muda: `fetchAll()` devolve as linhas, como uma chamada comum.
 *
 * **Transação = conexão exclusiva.** Fora de transação, cada comando pega qualquer conexão livre
 * do pool. Dentro de uma, todos os comandos precisam ir para a mesma conexão — e só eles. Por
 * isso a transação é "ligada" à Fiber corrente ({@see bind()}): o SQL daquela Fiber vai para a
 * transação; o das outras continua indo para o pool.
 */
final class Database
{
    public const int DEFAULT_MAX_CONNECTIONS = 16;

    /** @var (Closure(string, list<mixed>): void)|null */
    private ?Closure $listener = null;

    private readonly SqlDialect $dialect;

    private readonly PostgresConnectionPool $pool;

    /** @var FiberValue<PostgresTransaction> a transação ligada à Fiber corrente */
    private readonly FiberValue $bound;

    public function __construct(
        private readonly PostgresConfig $config,
        int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
    ) {
        $this->dialect = new PostgresDialect();
        // resetConnections: false — o projeto não guarda estado de sessão na conexão, e o
        // DISCARD ALL antes de cada uso custaria uma ida ao banco por comando
        $this->pool = new PostgresConnectionPool($config, max(1, $maxConnections), resetConnections: false);
        $this->bound = new FiberValue(PostgresTransaction::class);
    }

    /**
     * Abre pelo endereço do banco, no formato `host=localhost;port=5432;dbname=loja` (o prefixo
     * `pgsql:` é aceito). Se o banco ainda não existe, tenta criá-lo.
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
     * @throws \InvalidArgumentException endereço mal formado
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

        // datas sempre em UTC, em toda conexão (vale como padrão da sessão, desde a abertura)
        return $config->withOptions('-c TimeZone=UTC');
    }

    public function driver(): string
    {
        return $this->dialect->name();
    }

    /** O que muda de um banco para outro (sequências, paginação, JSON...). */
    public function dialect(): SqlDialect
    {
        return $this->dialect;
    }

    /**
     * Observa cada comando enviado ao banco (testes, depuração, log de SQL).
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
     * @return int linhas afetadas
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->getRowCount() ?? 0;
    }

    /** Executa vários comandos sem parâmetros (DDL). */
    public function exec(string $sql): void
    {
        if ($this->listener !== null) {
            ($this->listener)($sql, []);
        }
        $result = $this->executor()->query($sql);
        while ($result !== null) {   // um resultado por comando: consome todos
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

    /** Abre uma transação numa conexão do pool (ela fica presa à transação até o fim). */
    public function beginTransaction(): PostgresTransaction
    {
        return $this->pool->beginTransaction();
    }

    /**
     * Uma conexão só para quem pediu, fora do pool — para a transação remota da API REST, que
     * atravessa várias requisições e não deve ocupar uma conexão do pool por todo esse tempo.
     */
    public function newConnection(): PostgresConnection
    {
        return connect($this->config);
    }

    /**
     * Liga uma transação à Fiber corrente; null desliga. Daí em diante, o SQL desta Fiber vai
     * para a transação.
     *
     * @return PostgresTransaction|null a que estava ligada antes
     */
    public function bind(?PostgresTransaction $transaction): ?PostgresTransaction
    {
        $previous = $this->bound->get();
        $this->bound->set($transaction);

        return $previous;
    }

    /** A transação ligada à Fiber corrente, se houver. */
    public function boundTransaction(): ?PostgresTransaction
    {
        return $this->bound->get();
    }

    public function inTransaction(): bool
    {
        return $this->bound->get() !== null;
    }

    /** Fecha o pool (fim do servidor ou dos testes). */
    public function close(): void
    {
        $this->pool->close();
    }

    /** Para onde vai o SQL agora: a transação desta Fiber, ou qualquer conexão do pool. */
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
     * Conveniência de desenvolvimento: se o banco não existe, cria-o pela base de manutenção
     * `postgres`. Sem permissão para isso (comum em produção), a falha diz o que fazer.
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
                "O banco '{$config->getDatabase()}' não existe e não foi possível criá-lo: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
}
