# The framework, piece by piece

What matters for writing code. When in doubt, the source is `src/Framework/Cube/` and the
tests in `tests/Framework/Cube/` (`CubeNavigationTest` documents the cycle case by case).

## Contents

1. Packages and runtime
2. Session: `CubeApplication`
3. Places and routes
4. Navigation and lifecycle
5. Intent: parameters, attributes, slots
6. Presenters: screen, guard, child
7. View state and serialization
8. Events: `Skeleton`, form fields
9. The remote view and the protocol
10. The address: publication and signature
11. Client storage
12. Alerts and errors
13. The event loop: Fibers, locks, timers
14. Tests

---

## 1. Packages and runtime

- PHP 8.4+, Composer. PHPStan level 8 (`composer check` runs PHPStan + PHPUnit).
- Server: Amp v3 (`http-server`, `websocket-server`, router), Revolt event loop, Fibers.
  One process, one event loop, every session inside it.
- Database: PostgreSQL through `amphp/postgres` (asynchronous, pooled). See
  [persistence.md](persistence.md).
- Heavy CPU (PBKDF2 of the handshake) runs in worker processes (`amphp/parallel`).
- View: the React shell in `frontend/remote.shell.react` + the protocol client in
  `frontend/remote.bridge.react`, built with `bin/build-frontend.sh` into `work/frontend/`.

## 2. Session: `CubeApplication`

One instance per user session. The application subclass (`ShoppingApplication`) adds what
every presenter needs: the logged user (`subject()`), services and repositories (already
wrapped with the session's security), the transaction service (`tx()`), the scheduler
(`scheduler()`), the context (`context`, with the async `sleep`), `go()` by name and
`alertUnexpectedError()`.

The environment-dependent parts are abstract and implemented twice:

- the remote host (`ShoppingRemoteApplication`): `updateHistory()`, client storage, cipher,
  installs the `RemoteView` factories;
- the test application (`ShoppingApplicationMock`): in-memory storage, counts
  `updateHistory()` calls, installs the view mocks.

Useful API: `newIntent()` (intent filled by the live presenters), `navigate()`,
`presenterOf($place)`, `lastPlace()`, `fragment()`, `setAttribute/getAttribute` (session
attributes), `updateHistory()`.

## 3. Places and routes

```php
enum Place: int implements CubePlace
{
    case ROOT = 0;        // level 0
    case OPEN = 1;        // level 1
    case RESTRICTED = 2;
    case LOGIN = 3;       // level 2
    case HOME = 4;
    case CART = 5;        // level 3
    // ...
    public function name(): string { /* the name in the address */ }
    public function createPresenter(CubeApplication $app): CubePresenter { /* match */ }
    public function go(ShoppingApplication $app, CubeIntent $intent): bool { /* match → Routes */ }
}
```

- The id grows with depth: presenters are released leaf first.
- `Routes` has one static method per face, the chain from the root. `$intent` omitted means
  "from the current state" (`$app->newIntent()`).
- `$app->go('product?pr-id=3')` navigates by name (used when the client asks for an
  address, e.g. F5).

## 4. Navigation and lifecycle

- `Routes::xxx($app, $intent)` → `$app->navigate()->step(Place::ROOT)->...->execute($intent)`.
- `applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool` on each
  place, root to leaf. `false` stops the walk.
- Commit: presenters of the new chain stay; the rest are released leaf first. A presenter
  created in this navigation and not accepted is released too.
- Exception: rollback (the old presenters get the previous intent back; the new ones are
  released) and the exception is rethrown to the caller.
- Redirect: a presenter that calls `Routes::other($app, $intent)` inside `applyParameters`
  and returns `false` starts a new navigation; the outer one is marked interrupted and
  neither commits nor rolls back. A redirect loop is detected and the navigation abandoned,
  so the session stays usable.
- After the navigation, `navigationEnded` asks for `updateHistory()`.

## 5. Intent and keys: parameters, attributes, slots

The intent's raw API, used ONLY inside keys classes:

| | Goes to the address? | API | What for |
|---|---|---|---|
| Parameter | yes | `getParameterAsInt/AsString/AsFloat/AsBool($name, $default)`, `setParameter`, `removeParameter` | addressable state |
| Attribute | no | `setAttribute`, `getAttribute`, `removeAttribute` | data already in hand, flags ("purchase just made") |
| View slot | no | `setViewSlot($name, ?CubeViewSlot)`, `getViewSlot($name)` | where the next level draws |

- `CubeIntent::parse('product?pr-id=3')` and `(string) $intent` round-trip (names and values
  are URL-encoded). `setParameter($name, null)` removes it from the address.

**Keys** (`CubeKeys<A>` in the framework; `RootKeys` and one `XxxKeys` per place in the
application) are the typed reading of the intent and the only place that knows the names:

```php
final class ProductKeys extends RootKeys          // inherits userId and ownerSlot from the root
{
    private const string PREFIX = 'pr';               // unique in the application
    private const string P_ID = self::PREFIX . '-id'; // parameter: goes to the address

    public ?int $productId {                          // PHP 8.4 property hooks
        get => $this->idParameter(self::P_ID);        // validates: non-negative int or null
        set (?int $value) {
            $this->intent->setParameter(self::P_ID, $value);
        }
    }

    public function place(): CubePlace { return Place::PRODUCT; }
}
```

- `new ProductKeys($app, $intent)` reads/writes the intent of a navigation in progress
  (`applyParameters`, `publishParameters`).
- `ProductKeys::of($app)` builds a NEW intent from the current state (every live presenter
  publishes) with the keys' place; `->go()` navigates to it (`Place::go` → `Routes`).
- Writing a hooked property of a temporary object is a PHP error
  (`(new ProductKeys(...))->productId = 3` → "Cannot use temporary expression in write
  context"): assign the keys to a variable first.
- `KeysNamesTest` scans `src/Shopping/**/*Keys.php`: two-letter prefix unique per file, every
  name `self::PREFIX . '-code'` in a `P_`/`A_` constant, no `$intent->...('literal')` anywhere
  outside the keys.

**Slots.** `new CubeViewSlot(fn (?CubeView $v) => ...)` wraps a setter. The owner keeps it in
a field and offers it when it is not the leaf:

```php
if ($deepest) {
    $this->setContentView(null);                                       // I am the destination
} else {
    $keys->ownerSlot = $this->contentSlot;   // re-offered under the same name
}
```

The child reads it on every call and puts its view in it. The owner's view state has a
`?CubeView $contentView` that the serializer sends as `contentViewId`; the React view draws
it with `bridge.createView(state.contentViewId)`.

## 6. Presenters: screen, guard, child

- `AbstractCubePresenter` (screen): holds `$this->view`, `update()` marks it dirty,
  `release()` releases the view. Override `applyParameters`, `publishParameters`,
  `commitComputedState`, `release` (call `parent::release()` last).
- Guard: implement `CubePresenter` directly, without a view; check and redirect.
- `AbstractChildPresenter` (segment of an owner's view, no place): implement
  `onCreateView()` and `onInitialize()`; the owner calls `initialize()` (returns the view to
  put in a state field) and `release()`. It receives its owner in the constructor and
  delegates navigation to it (`$this->owner->onOpenProduct($id)`).

`$createView` is a static closure per presenter class, installed by whoever hosts the
application (remote host or test). The presenter never knows the view's concrete class.

## 7. View state and serialization

`XxxViewState implements ViewState` - public properties only. Serialized by
`ViewStateSerializer` at the end of each request for every dirty view:

- `"#"` = view instance id (`<classId>:<n>`), always first;
- public, non-static properties, except `#[Transient]`;
- `bool`, `int`, `float` (non-nullable) are always written, `0` and `false` included;
- `null` and blank strings are omitted;
- a `CubeView` becomes `"<name>Id": "<instance id>"`;
- inside lists and objects: `null` stays `null`, a `CubeView` becomes its id, strings are not
  filtered, objects are written with their public non-null properties.

Consequences: values arrive READY to show (formatted, with defaults); presentation structs
(`ProductInfo`, `CartItem`) have public fields; the React type marks omittable fields as
optional and defaults them.

`commitComputedState()` runs right before serialization (inside the session lock): compute
derived values there, and `update()` only when something changed.

## 8. Events: `Skeleton`, form fields

```php
public function skeleton(): CubeSkeleton
{
    return new Skeleton(
        '48b693f67410',                                                // classId, also in the React view
        function (int $event, int $qtde, array $form): void {          // submit
            match ($event) {
                1 => $this->onOpenProducts(),
                2 => $this->onAddToCart(Coerce::asInt($form['p.quantity'] ?? null)),
                default => Skeleton::unknownEvent($event),
            };
        },
        function (array $form): void {                                 // syncState (optional)
            $this->state->draft = Coerce::asString($form['draft'] ?? null);
        },
    );
}
```

- The client sends `{requestId, event: ["<viewId>:<code>"], "<viewId>": {fields}}`. Repeated
  events in the same request arrive once with `$qtde` = count.
- Order per request: first the plain fields of every view go to `syncState` (inside the
  session lock), then each event goes to `submit` (outside the lock, so a long event lets the
  background flush send intermediate state).
- `p.`-prefixed fields are the event's arguments; plain fields are state sync.
- Values arrive as JSON scalars: coerce (`Coerce::asInt`, `Coerce::asString`) and validate.
- An event for a view the server no longer has makes the server resend every view.

## 9. The remote view and the protocol

- Session start: `GET /<shell>/index.html` sets the cookies `app_id` (signed session id) and
  `app_skey` (handshake public key); clients without cookies use `GET /api/session/init`.
- WebSocket `/dispatcher/<appId>`, subprotocol `wdc`. The first message carries a secret
  encrypted with RSA; both sides derive an AES-256-GCM key (PBKDF2, 250k iterations) used for
  the secure part of the client storage.
- Server → client: `{requestId?, storage?, uri?, states?, releasedViews?, activeViews?}`;
  `states` is the list of serialized view states.
- Dirty views are sent at the end of each request, and by a background flush every 50 ms
  (state changed by timers or during a long event).
- Close codes: `4001` (invalid session - the shell reloads) and `4003` (capacity,
  `server.maxSessions`).
- Sessions live in `RemoteApplicationRegistry`; a session without a connection expires after
  `server.sessionTtlSeconds`.

## 10. The address: publication and signature

After each navigation, and whenever `updateHistory()` is called, the session builds
`<deepest place name>?<published parameters>` and appends
`sign=<base62(md5(rsa-sign(address)))>`. When the client asks for an address (F5, Back), a
missing or wrong signature makes the server ignore it and reaffirm the current state. The
signature prevents tampering, not stale data: validate parameters anyway.

## 11. Client storage

- `clientSessionStore()` (the browser tab) and `clientPersistentStore()` (across visits),
  each with a `secure()` scope encrypted with the session key.
- Changes are sent as deltas in the next response; the client sends its storage at session
  start, so a rebuilt session can recover from it (`applyParameters`, root auto-login).
- Keys live in `StorageKeys`.

## 12. Alerts and errors

- `$this->app->alertUnexpectedError($message, $caught)` → the root presenter shows the alert
  and logs the exception.
- Expected failures become an error code + message in the presenter's own state
  (`errorCode`, `errorMessage`), which its React view shows.
- An exception escaping a skeleton is caught by the session (`submitting event`), alerted, and
  the view is marked dirty.

## 13. The event loop: Fibers, locks, timers

- Each WebSocket connection is a Fiber; its messages are processed one at a time.
- A database query suspends only the Fiber that made it: other sessions run meanwhile, and
  queries of different sessions run in parallel (one pool connection each).
- **Per-Fiber context**: `FiberValue<T>` (over Revolt's `FiberLocal`). Used by the open
  transaction (`Database`), the transaction context (`SqlTransactionService`) and the
  security context (`CurrentSecurityContext`). Cleared by the loop after each callback.
- **Session lock**: `RemoteApplicationSupport` runs navigation from the client (`safeGo`), form
  sync, `commitComputedState` and the alert inside a reentrant per-session lock
  (`ReentrantLock` over `Amp\Sync\LocalMutex`). Event handling (`submit`) runs outside it.
- **Session creation** is guarded per `appId` (`LocalKeyedMutex`), so two connections with the
  same id do not create two sessions.
- **Timers**: `$this->app->scheduler()->schedule(fn () => ..., $seconds)` and
  `scheduleAtFixedRate(...)` return a `Registration`; `remove()` it in `release()`.
- **Waiting**: `($this->app->context->sleep)($seconds)` - asynchronous. PHP's `sleep()` blocks
  every session.
- Blocking I/O (`file_get_contents` on a URL, a synchronous HTTP client) blocks every session:
  use the Amp clients.

## 14. Tests

- `tests/Shopping/Presentation/PresentationTestCase`: a fresh seed in the test database per
  test, real authentication, a `ManualScheduler` (advance the clock by hand), and
  `ShoppingApplicationMock` with view mocks. `login()` returns the home view mock.
- A view mock keeps the presenter and the SAME state object (`$mock->state`), counts
  `update()`s, and `::cast($view)` asserts the type with a useful message.
- Simulate the client by calling the presenter methods the skeleton would call
  (`$home->presenter->onOpenProduct($id)`), then assert the mocks' state.
- Protocol-level tests (`tests/Shopping/RemoteHost`) drive a whole session through the wire
  format with a fake browser; end-to-end server tests (`tests/Shopping/Server`,
  `tests/Shopping/Rest`) start the real server on a free port.
- The database: PostgreSQL configured in `[test.database]` of `application.local.toml`
  (or `WDC_TEST_DB_*` variables); recreated per run, re-seeded per test.
