<?php

declare(strict_types=1);

namespace Wdc\Framework\Persistence;

use Closure;
use PDO;
use PDOStatement;
use RuntimeException;
use Wdc\Framework\Commons\Lang\Coerce;
use Wdc\Framework\Persistence\Dialect\PostgresDialect;
use Wdc\Framework\Persistence\Dialect\SqlDialect;
use Wdc\Framework\Persistence\Dialect\SqliteDialect;

/**
 * Fina camada sobre o PDO.
 *
 * Uma conexão por processo: o servidor é um processo único de longa duração (event loop),
 * e as chamadas PDO são síncronas — enquanto uma query roda, nenhuma outra Fiber roda.
 *
 * A exceção é a transação remota (API REST): ela atravessa várias requisições HTTP e por isso
 * tem uma conexão só dela ({@see newConnection()}). Durante a requisição que a usa, essa conexão
 * é "ligada" ({@see bind()}) e todo SQL vai para ela. Como o SQL é síncrono, nenhuma outra Fiber roda no meio.
 */
final class Database
{
    /** @var (Closure(string, list<mixed>): void)|null */
    private ?Closure $listener = null;

    private readonly SqlDialect $dialect;

    /** Conexão de uma transação remota, enquanto ligada; null = a conexão principal. */
    private ?PDO $bound = null;

    /**
     * @param (Closure(): PDO)|null $connector abre outra conexão ao mesmo banco (null = não dá)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Closure $connector = null,
    ) {
        $this->dialect = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => new SqliteDialect(),
            'pgsql' => new PostgresDialect(),
            default => throw new RuntimeException('Banco não suportado: ' . Coerce::asString($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))),
        };
        $this->configure($pdo);
    }

    /**
     * Abre pelo DSN do PDO: `sqlite:/caminho/arquivo.db`, `sqlite::memory:`,
     * `pgsql:host=localhost;port=5432;dbname=...`.
     */
    public static function open(string $dsn, ?string $user = null, ?string $password = null): self
    {
        return str_starts_with($dsn, 'sqlite:')
            ? self::sqlite(substr($dsn, strlen('sqlite:')))
            : new self(new PDO($dsn, $user, $password), static fn () => new PDO($dsn, $user, $password));
    }

    /** Abre um SQLite em arquivo, ou em memória com ':memory:'. */
    public static function sqlite(string $path): self
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create directory {$dir}");
            }
        }

        // um banco em memória só existe na própria conexão: não há como abrir uma segunda
        $connector = $path === ':memory:' ? null : static fn () => new PDO('sqlite:' . $path);

        return new self(new PDO('sqlite:' . $path), $connector);
    }

    public function driver(): string
    {
        $name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return is_string($name) ? $name : '';
    }

    /** A conexão em uso agora: a da transação remota ligada, ou a principal. */
    public function pdo(): PDO
    {
        return $this->bound ?? $this->pdo;
    }

    /** Se dá para abrir outra conexão ao mesmo banco (não dá num SQLite em memória). */
    public function canOpenConnections(): bool
    {
        return $this->connector !== null;
    }

    /** Abre outra conexão ao mesmo banco, configurada como a principal. */
    public function newConnection(): PDO
    {
        if ($this->connector === null) {
            throw new RuntimeException('Este banco não aceita uma segunda conexão (SQLite em memória?)');
        }
        $pdo = ($this->connector)();
        $this->configure($pdo);

        return $pdo;
    }

    /**
     * Liga uma conexão (de transação remota) como a corrente; null volta à principal.
     *
     * @return PDO|null a que estava ligada antes
     */
    public function bind(?PDO $connection): ?PDO
    {
        $previous = $this->bound;
        $this->bound = $connection;

        return $previous;
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
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();
        foreach ($rows as &$row) {   // & = altera o próprio elemento, não uma cópia
            foreach ($row as &$value) {
                if (is_resource($value)) {   // o pdo_pgsql devolve BYTEA como stream
                    $value = stream_get_contents($value);
                }
            }
            unset($value);
        }
        unset($row);

        return $rows;
    }

    /** @param list<mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param list<mixed> $params
     * @return int linhas afetadas
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** Executa vários comandos sem parâmetros (DDL). */
    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    public function nextval(string $sequence): int
    {
        return $this->dialect->nextval($this, $sequence);
    }

    public function restartSequence(string $sequence, int $next): void
    {
        $this->dialect->restartSequence($this, $sequence, $next);
    }

    private function configure(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // nomes de coluna sempre em minúsculas: o PostgreSQL já devolve assim, o SQLite não
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->dialect->configure($pdo);
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        if ($this->listener !== null) {
            ($this->listener)($sql, $params);
        }
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $i => $value) {
            [$bound, $type] = match (true) {
                $value === null => [null, PDO::PARAM_NULL],
                is_int($value) => [$value, PDO::PARAM_INT],
                is_bool($value) => [$value ? 1 : 0, PDO::PARAM_INT],
                $value instanceof Blob => [$value->bytes, PDO::PARAM_LOB],
                default => [$value, PDO::PARAM_STR],
            };
            $stmt->bindValue($i + 1, $bound, $type);   // PDO numera parâmetros a partir de 1
        }
        $stmt->execute();

        return $stmt;
    }
}
