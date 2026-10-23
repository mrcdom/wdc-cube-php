# Persistence: entities, criteria, projection, JsonQuery

The data layer is part of the architecture, not an afterthought: its central mechanism - a
projection turned into ONE query that returns the whole graph as JSON - is what keeps
screens fast and free of N+1. Sources: `src/Framework/Domain/`, `src/Framework/Persistence/`,
and the Store repositories in `src/Store/Persistence/Repository/`.

## Contents

1. The entity
2. The criteria
3. The projection
4. The repository contract
5. A repository implementation
6. JsonQuery: what it generates
7. Transactions
8. Security in the data layer
9. The asynchronous database
10. Schema and seed
11. The REST API
12. Rules that break silently

---

## 1. The entity

A plain class with nullable fields and a fluent API: `field()` reads, `withField()` writes
and returns `$this`. `null` means "not loaded / not projected".

```php
final class Product
{
    private ?int $id = null;
    private ?string $name = null;
    private ?float $price = null;

    public function id(): ?int { return $this->id; }
    public function withId(?int $id): static { $this->id = $id; return $this; }
    // ...
}
```

Relations are entities (`Purchase::user(): ?User`) and lists (`Purchase::items()`: a list of
`PurchaseItem` or a `ProjectionList` when used as a projection). Ids start at 0 in the seed:
never test an id with `!$id`.

## 2. The criteria

One `XxxCriteria` per entity, with a `Criterion` per filterable field (created in the
constructor, never null), an optional projection and an `XxxOrderBy` enum.

```php
$criteria = (new ProductCriteria())
    ->withProjection(ProductInfo::projection())
    ->withOrderBy(ProductOrderBy::CHEAPEST_FIRST);
$criteria->price()->ge(10.0);                                   // ComparableCriterion: gt ge lt le between
$criteria->name()->or()->like('Wilson%')->name()->like('Thread%');  // TextCriterion: like ilike containing startingWith
$criteria->productId()->in([1, 2, 3]);                          // eq ne in isNull isNotNull
```

- Requests on the same field are ANDed; `or()` makes that field disjunctive.
- A `null` value adds no request: an empty screen filter means "do not filter".
- `withXxx($value)` is the shortcut for `xxx()->eq($value)`.
- Virtual fields are allowed (`PurchaseCriteria::productId` = "has an item with this product",
  translated to `EXISTS`).
- Without `orderBy`, results come ordered by id (never in physical order).

## 3. The projection

The entity itself as a mask: a non-null field = a column to select. The values are
conventions from `ProjectionValues` (`PV::INT`, `PV::FLOAT`, `PV::STR`, `PV::BOOL`,
`PV::dateTime()`).

```php
$projection = (new Purchase())
    ->withId(PV::INT)
    ->withBuyDate(PV::dateTime())
    ->withUser((new User())->withId(PV::INT)->withName(PV::STR))            // N:1, nested
    ->withItems(PV::singletonList(                                           // 1:N, with its own criteria and slice
        (new PurchaseItem())->withAmount(PV::INT)->withProduct((new Product())->withName(PV::STR)),
        (new PurchaseItemCriteria())->withOrderBy(PurchaseItemOrderBy::MOST_EXPENSIVE_FIRST),
    )->withLimit(3));
```

- A relation that projects ONLY the id comes from the FK column, with no subquery.
- The 1:N slice (`withLimit`/`withOffset`) applies PER PARENT.
- Keep each screen's projection next to the struct it fills (`ProductInfo::projection()`),
  so "what the screen shows" and "what the query selects" change together.

## 4. The repository contract

`Repository<E, C>`: `insert`, `update($new, $old = null, $projection = null)`,
`insertOrUpdate`, `delete($criteria)`, `count($criteria)`,
`fetch($criteria, $offset = 0, $limit = 0)`, `fetchPage($criteria, $page, $pageSize)`,
`fetchById($id, $projection = null)`, `newProjection()`.

- `insert` generates the id from the sequence when it is null and writes it back to the bean.
- `update` writes only the fields that are both projected AND changed relative to `$old`
  (`$old = null` ⇒ every projected field).
- Domain interfaces (`ProductRepository`) add specific operations (`fetchImage`).
- **Getting one**: `Beans::of(ProductRepository::class)` (`Wdc\Framework\Commons\Beans`,
  typed by PHPStan). `StoreRepositories::register($db)` (stateless, no fields) puts every repository and the
  `TransactionService` there by interface, once at startup; the server registers the optional
  `AuthenticationService` (read with `Beans::find`, which may return `null`). One instance per
  process serves every session: repositories hold no per-call state. A service looks up its own
  dependencies in its constructor (`$this->products = Beans::of(ProductRepository::class)`,
  `readonly` fields); whoever creates it just does `new ProductService()`. Tests swap an
  implementation with `Beans::set` before creating the service. A new
  repository = its Impl + one line in `register()`
  (`Beans::set(ReviewRepository::class, new ReviewRepositoryImpl($db))`); no field per repository,
  no getter in the session and no constructor argument anywhere else.
- **A repository that uses another** keeps a nullable field of the *concrete* type and a private
  factory that looks it up once: `return $this->userRepository ??= self::repositoryOf(UserRepository::class, UserRepositoryImpl::class);`.
  `AbstractRepository::repositoryOf` does `Beans::of($interface)` and checks `instanceof $impl`
  (the domain interface has no `query()`: the domain knows nothing of SQL). Relations use it in a
  closure (`fn () => $this->userRepository()->query()`, not `static`), writes call it directly
  (`$this->purchaseItemRepository()->insert(...)`).

## 5. A repository implementation

```php
/** @extends AbstractRepository<Purchase, PurchaseCriteria> */
final class PurchaseRepositoryImpl extends AbstractRepository implements PurchaseRepository
{
    // The repository owns its table, sequence and column names; public because other
    // repositories use them too (EXISTS, cascade DELETE).
    public const string TABLE = 'EN_PURCHASE';
    public const string SEQUENCE = 'SQ_PURCHASE';
    public const string ID = 'ID';
    public const string USERID = 'USERID';
    public const string BUYDATE = 'BUYDATE';

    /** @var JsonQuery<Purchase, PurchaseCriteria> */
    private readonly JsonQuery $query;

    private ?UserRepositoryImpl $userRepository = null;

    private ?PurchaseItemRepositoryImpl $purchaseItemRepository = null;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $table = new Table(self::TABLE, static fn (): Purchase => new Purchase(), [
            new Column('id', self::ID, ColumnType::Int, static fn (Purchase $p) => $p->id(), static fn (Purchase $p, mixed $v) => $p->withId($v)),
            new Column('buyDate', self::BUYDATE, ColumnType::DateTime, static fn (Purchase $p) => $p->buyDate(), static fn (Purchase $p, mixed $v) => $p->withBuyDate($v)),
        ]);
        // targets in closures: purchase → item → purchase is a cycle, and the other repository
        // may not be registered in Beans yet when this constructor runs
        $this->query = new JsonQuery($table, PurchaseCriteria::class, 'pu', $db->dialect())
            ->withConditions($this->conditions(...))
            ->withOrdering($this->orderBy(...))
            ->addToOne('user', static fn (Purchase $p) => $p->user(), static fn (Purchase $p, ?User $u) => $p->withUser($u),
                fn () => $this->userRepository()->query(), self::USERID)
            ->addToMany('items', static fn (Purchase $p) => $p->items(), static fn (Purchase $p, array $items) => $p->withItems($items),
                fn () => $this->purchaseItemRepository()->query(), PurchaseItemRepositoryImpl::PURCHASEID);   // FK column of the CHILD table
    }

    public function query(): JsonQuery { return $this->query; }

    /** Looked up in Beans on the first call and kept; later calls return the field. */
    private function userRepository(): UserRepositoryImpl
    {
        return $this->userRepository ??= self::repositoryOf(UserRepository::class, UserRepositoryImpl::class);
    }

    private function purchaseItemRepository(): PurchaseItemRepositoryImpl   // also used by insert() to write the items
    {
        return $this->purchaseItemRepository ??= self::repositoryOf(PurchaseItemRepository::class, PurchaseItemRepositoryImpl::class);
    }

    protected function sequence(): string { return self::SEQUENCE; }

    protected function conditions(object $criteria, string $a, QueryContext $ctx): ?Sql
    {
        $c = Sql::alias($a);                                          // $c(self::ID) → "pu1.ID"

        return Sql::and([
            CriterionTranslator::translate($c(self::ID), $criteria->purchaseId()),
            CriterionTranslator::translate($c(self::USERID), $criteria->userId()),
            CriterionTranslator::translate($c(self::BUYDATE), $criteria->buyDate(), ColumnType::formatDate(...)),
            // ...
        ]);
    }

    protected function orderBy(?object $criteria, string $a): string
    {
        $c = Sql::alias($a);

        return match ($criteria?->orderBy()) {
            null => '',                                               // ⇒ by id
            PurchaseOrderBy::NEWEST_FIRST => "{$c(self::ID)} DESC",
            PurchaseOrderBy::MOST_RECENT_PURCHASE_FIRST => "{$c(self::BUYDATE)} DESC, {$c(self::ID)} DESC",   // tie-break by id
            // ...
        };
    }

    protected function projectionOf(object $criteria): ?object { return $criteria->projection(); }

    protected function criteriaForIds(array $ids, object $projection): object
    {
        return (new PurchaseCriteria())->purchaseId()->in($ids)->withProjection($projection);
    }
}
```

- Table, sequence and column names are public class constants of the repository that owns the
  table, used everywhere instead of string literals: a typo is an undefined constant that PHPStan
  reports in `composer check` (instead of an SQL error when that query first runs), and the
  `Table` and the queries cannot diverge. PHP does not interpolate constants in strings, so
  `Sql::alias($a)` returns a closure (`$c`) whose call does interpolate: `"{$c(self::ID)} DESC"`.
  Hand-written SQL concatenates: `'EXISTS (SELECT 1 FROM ' . PurchaseItemRepositoryImpl::TABLE . " {$pi} WHERE ...)"`.
  The DDL in `StoreSchema` stays plain SQL (repository tests catch divergence).
- Columns that are FKs and not fields (`USERID`) are written by `insertBean($bean, [self::USERID
  => ...])` / `updateBean(..., [self::USERID => [getter, ColumnType]])`.
- SQL identifiers never use quotes (PostgreSQL lowercases unquoted names).
- Every ORDER BY ends with a unique tie-break (the id): pagination needs a total order.
- Register the repository with one `Beans::set` line in `StoreRepositories::register($db)`.

## 6. JsonQuery: what it generates

One SELECT per `fetch`, whatever the depth of the projection:

```
SELECT {"id": pu1.ID, "buyDate": pu1.BUYDATE,
        "user":  (SELECT {"id": u2.ID, "name": u2.NAME} FROM EN_USER u2 WHERE u2.ID = pu1.USERID),
        "items": (SELECT [ {...} ORDER BY pi3.PRICE DESC, pi3.ID ] FROM EN_PURCHASEITEM pi3
                  WHERE pi3.PURCHASEID = pu1.ID AND pi3.ID IN (<slice per parent>))} AS json
FROM EN_PURCHASE pu1 WHERE ... ORDER BY ... LIMIT ...
```

(`{...}`/`[...]` are built with the database's JSON functions - `JsonDialect`.) The result is
parsed and hydrated into entities. Inspect the generated SQL in a test with
`$db->onQuery(fn (string $sql, array $params) => ...)`.

## 7. Transactions

```php
Beans::of(TransactionService::class)->required(function (TransactionContext $tx) use ($purchase): void {
    $this->purchases->insert($purchase);        // purchase + items, atomically
});
```

- REQUIRED, reentrant: the outermost call owns the transaction (a pool connection bound to
  the current Fiber); inner calls take part; an exception in a participant marks
  rollback-only.
- Other sessions keep running in the meantime, outside the transaction.
- Work started with `async()` inside `required()` runs in another Fiber: OUTSIDE the
  transaction.

## 8. Security in the data layer

- Permissions are checked ONLY at the REST endpoints: there the request comes ready-made from a
  client we do not control. Presenters use the repositories directly (`Beans::of(XxxRepository::class)`,
  no security wrapper): they run on the server and are written to respect the authorization
  rules from the session's user (`$app->subject()`), and the browser only gets what the screen
  draws. Do not add a security decorator to the presentation.
- The REST security context (`CurrentSecurityContext`) is per Fiber: never keep it in a static
  field.
- Permissions are `entity:operation` (`purchase:write`) plus `data:all`; roles in `Role`.
- Without `data:all`, a user only sees and changes their own data: the REST controllers
  force the owner criterion (`SecurityEnforcer::forceOwner`, which also neutralizes an
  `"or": true` sent by the client) and check ownership before updates.

## 9. The asynchronous database

- `Database` wraps an `amphp/postgres` pool (`database.pool.maxConnections`, default 16).
  Connections open on demand and close after 60 s idle.
- A query suspends only its Fiber; queries of different sessions run in parallel.
- API: `fetchAll($sql, $params)`, `fetchValue`, `execute` (affected rows), `exec` (DDL,
  several statements), `nextval`, `beginTransaction`, `bind` (transaction ↔ Fiber).
- Parameters are positional `?`. Binary values go wrapped in `Blob`. Dates are stored as
  UTC `TIMESTAMP` (the session runs in UTC).
- The REST API's remote transactions use a dedicated connection per transaction, outside
  the pool.

## 10. Schema and seed

`StoreSchema`: DDL (`create` if the tables do not exist, `drop`), `reset` (delete all and
seed). Seed ids are public constants (`StoreSchema::PEN_DRIVE2GB_ID`), used by the tests
(`use StoreSchema as Seed`). The server creates the database if it does not exist and
the schema on first start.

## 11. The REST API

Repositories are also exposed over HTTP (`src/Store/Persistence/Rest/`):
`/api/auth/*` (HMAC challenge, JWT), `/api/repo/<entity>/<operation>`, `/api/tx/*` (remote
transaction with `X-Tx-Id`). Each entity has a `ModelCodec` (`ProductCodec`...) that reads
and writes the JSON, including criteria (`{"price": {"or": true, "p": [{"o": "GE", "v": [10]}]}}`)
and projected collections (`{"shape": {...}, "where": {...}, "limit": 5}`). A new entity
field needs: entity, column, codec (read, read-for-update, write), and - if filterable -
criteria, translator and codec criteria field.

## 12. Rules that break silently

- [ ] Every `fetch`/`fetchById` from a screen passes a projection with exactly what it shows.
      *Symptom: slow screens, large payloads, a field "randomly" null because the default
      projection did not include it.*
- [ ] No repository call inside a loop over rows: put the relation in the projection.
      *Symptom: N+1 - one query per row, latency growing with the list.*
- [ ] Each 1:N projection that can be large carries a limit and an order.
- [ ] ORDER BY ends with the id. *Symptom: a row appears twice or never across pages.*
- [ ] Table, sequence and column names only through the owning repository's public constants
      (`self::RATING`, `PurchaseItemRepositoryImpl::PURCHASEID`), never as string literals.
      *Symptom: a typo surfaces as an SQL error only when that query runs, not in PHPStan.*
- [ ] A new repository is registered in `StoreRepositories::register()`. *Symptom:
      "No implementation registered for ...XxxRepository" on the first screen that uses it.*
- [ ] Repositories and services keep nothing of a call in a field (they are shared by every
      session). *Symptom: one user's data showing up in another session.*
- [ ] Several writes in one `required()`. *Symptom: half an operation saved after an error.*
- [ ] No `async()` expecting to be inside the transaction.
- [ ] Criteria built from screen input: `null` means "no filter" - an id that must restrict
      the query cannot come in null. *Symptom: a list that should be empty shows everything.*
- [ ] Dates: write any timezone (the column type converts to UTC) and expect UTC back. The
      reader's local time is the view's concern: send the instant (e.g. epoch milliseconds,
      as the receipt does) and let the browser format it. *Symptom: times off by the
      server's offset.*
