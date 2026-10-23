---
name: cube-architecture-php
description: How to write, change, review and debug code in the PHP version of the Cube architecture - presenters running on a long-lived PHP server (Amp event loop) with a remote React view over WebSocket - places, CubeIntent, presenters, ViewState, Skeleton events, view slots, child presenters, the signed address derived from state, and the persistence layer (Repository, Criteria, projection, JsonQuery). Use ALWAYS when the task touches `src/Framework/Cube/**`, `src/Shopping/**` or a project built on them - `*Presenter.php`, `*ViewState.php`, `*Keys.php`, `Place.php`, `Routes.php`, `applyParameters`, `publishParameters`, `Skeleton`, `CubeViewSlot`, `$createView`, `commitComputedState`, `*Repository*.php`, `*Criteria.php`, `JsonQuery`, the React views in `frontend/shopping.shell.react` - including "create a screen", "add a parameter to the URL", "take the user from one screen to another", "add a panel", "review this presenter", "write a repository query", or investigating a "blank area", "button that does nothing", "screen that does not change", "eventCode not handled", "N+1", "server that freezes".
---

# Cube architecture (PHP, remote view)

## The concept: a cube of possible faces

Architectures such as Redux or XState treat application state as a separate structure that
events rewrite. Cube starts somewhere else.

**Two kinds of state, each with an owner.** RENDERING state is the presenter's **view
state** (`ViewState`): what the screen draws. CONTROL state is the remaining fields of the
**presenter**: what it needs to know in order to decide. Both are mutable, segmented by
presenter, and owned by it. There is no global store and no event to interpret: whoever
changes the state is whoever owns it.

**The presenters live on the server.** Each user session is a `CubeApplication` inside a
long-lived PHP process. The browser runs a thin React shell: it draws the view states it
receives over a WebSocket and sends back the user's events. The client never touches data;
it gets exactly what the screen shows.

**A tree of owners.** A presenter can own other presenters. At the top is the root
presenter, which also owns the application-wide alert view (the "browser view").

**The cube and its faces.** The application skeleton admits a set of possible compositions
of presenters - the **places** (a `CubePlace` enum) and the **routes** that chain them. Each
composition is a **face**; the address of a face is the name of its deepest place plus the
parameters, such as `#product?rt-user=0&pr-id=3&sign=...`. An **intent** (`CubeIntent`) is the
request to change face, with the parameters it needs.

**The contract of whoever takes part in a face.** Every presenter on a face implements:

| Method                 | Responsibility                                                                          |
| ---------------------- | --------------------------------------------------------------------------------------- |
| `applyParameters`      | Accept (or refuse) the new state, and RECOVER the state from the intent's parameters    |
| `publishParameters`    | Publish the MINIMAL identification of the state, the one that is enough to recreate it  |
| `commitComputedState`  | Recompute derived state right before the view state is sent to the client               |
| `release`              | Release whatever needs special handling when the presenter leaves the face              |

**The identity of the state.** The sum of what the presenters publish is the identity of
the application state, and it lives in the address. The server signs it (`sign=`): an
address edited by hand, or without a valid signature, is ignored and the current state is
reaffirmed. This discipline is what makes F5, a bookmark or Back put the application back
exactly as it was: `applyParameters` reads the intent that came from the address and
rebuilds the state.

**Reconnection.** If the WebSocket drops, the session waits (`server.sessionTtlSeconds`,
180 s by default) and continues where it was. If the session is gone, a new one is built
from what the client keeps: the address and the client storage (which holds the auto-login
token). What lived only in presenter memory (a cart, a half-filled form) is lost - unless the
presenter put it in the client storage or the database.

**Child presenters: the cube composes the skeleton, the owner composes the segments.** The
cube controls the places. Inside the view of a presenter that is on a face, a visual
SEGMENT can have its own presenter (`AbstractChildPresenter`): a panel, a tab. The OWNER
creates, keeps and releases it, and decides which one is active. Switching a panel does not
switch faces.

### From concept to code

| Concept                        | In the code                                                                                         |
| ------------------------------ | --------------------------------------------------------------------------------------------------- |
| Cube                           | `Place` enum (implements `CubePlace`) + `Routes` (one static method per face: the chain of places) |
| Intent                         | `CubeIntent`: place + parameters (go to the address) + attributes and view slots (do not)           |
| Change face                    | `XxxKeys::of($app)->go()` → `Place::go` → `Routes` → `$app->navigate()->step(...)->execute()`       |
| Session                        | `CubeApplication` subclass (`ShoppingApplication`); remote host subclass for the WebSocket session  |
| Presenter                      | `AbstractCubePresenter`; control state is its fields; `public readonly XxxViewState $state`         |
| Rendering state                | `XxxViewState implements ViewState`: public properties, serialized to JSON for the React view       |
| Redraw request                 | `$this->update()` - explicit; nothing observes the state                                            |
| Events from the client         | `skeleton()` returns a `Skeleton(classId, closure)`; event code → presenter method                  |
| Where a child appears          | `CubeViewSlot`, offered in the intent as `$keys->ownerSlot` (`RootKeys`)                            |
| Segment with its own presenter | `AbstractChildPresenter`, created and released by the owner; its view goes in a state field        |
| Typed reading of the intent    | keys class (`XxxKeys extends RootKeys`), the only one that knows parameter and attribute names       |
| The view                       | React class in `frontend/shopping.shell.react/src/scripts/views/`, registered by the same `classId`   |

Almost every defect in a Cube application comes from breaking the contract without
noticing - and almost all of them break SILENTLY: it compiles, the test passes, and the
screen does the wrong thing. That is why the rules come with the why.

## First: find your bearings in the project

1. **Where each layer lives.** Framework in `src/Framework/` (`Cube/` engine, `Cube/Remote/`
   protocol, `Persistence/`, `Domain/`, `Rest/`, `Commons/`). Application in `src/<App>/`:
   `Domain/` (entities, criteria, codecs), `Persistence/` (repositories, schema, REST API),
   `Presentation/` (places, routes, presenters, view states, services), `RemoteHost/`
   (the WebSocket session) and `Server/`. Views in `frontend/shopping.shell.react/`.
2. **The local conventions.** Read `README.md`, `docs/architecture.md` and the docblocks of
   `Place.php`, `Routes.php` and the keys classes (`Presenter/RootKeys.php` and each `*Keys.php`).
3. **Run the checks before and after**: `composer check` (PHPStan level 8 + PHPUnit; the
   tests need the PostgreSQL test database configured in `work/config/application.local.toml`).

## Changing face: the cycle that explains almost every rule

Everything goes through `CubeNavigation` (`$app->navigate()->step(...)->execute($intent)`).

1. The navigation walks the chain of places, from the root down.
2. For each place: if the presenter already exists, it is **reused** and gets
   `applyParameters($intent, initialization: false, $deepest)`; otherwise it is **created**
   (`Place::createPresenter`) and gets `initialization: true`. `$deepest` is true only at
   the leaf.
3. A presenter that returns `false` **stops the walk** (guard, redirect).
4. **Commit**: the presenters of the new chain stay; the others are released, leaf first.
   **Exception** → **rollback**: the presenters that already existed get the previous intent
   back, the new ones are released, and the exception is RETHROWN to whoever navigated.
5. If another navigation started in the middle (a redirect), this one was **interrupted**:
   it neither commits nor rolls back.
6. Then the address is rebuilt from the live presenters (`publishParameters`) and signed.

Consequences:

- **A presenter that stays on the path survives.** Create the view, children and listeners
  once (`if ($initialization)`); read parameters and the slot on every trip.
- **Identity is derived, not stored.** What a presenter does not publish disappears from
  the address. Whoever left the face stops publishing.
- **A failed navigation is an exception at the caller.** The event handler that called
  `$keys->go()` catches the specific exception (e.g. `ProductNotFoundException`) and shows
  an error code in ITS OWN state; the screen stays where it was.

## The skeleton of a presenter

```php
/** @extends AbstractCubePresenter<ShoppingApplication> */
final class OrderPresenter extends AbstractCubePresenter
{
    /** @var (Closure(self): CubeView)|null installed by the host (remote view) and by the tests (mock) */
    public static ?Closure $createView = null;

    public readonly OrderViewState $state;
    private readonly OrderService $service;
    private ?CubeViewSlot $ownerSlot = null;

    public function __construct(ShoppingApplication $app)
    {
        parent::__construct($app);
        $this->state = new OrderViewState();
        $this->service = new OrderService()   // the service looks up its repositories in Beans;
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $keys = new OrderKeys($this->app, $intent);            // the typed reading of the intent
        $orderId = $keys->orderId ?? throw new OrderNotFoundException();   // the keys already validated
        if ($initialization) {
            $this->view = (self::$createView ?? throw new LogicException('OrderPresenter::$createView not set'))($this);
        }
        if ($this->state->order?->id !== $orderId) {          // passing through is not reloading
            $this->state->order = $this->service->loadOrder($orderId);   // throws ⇒ rollback
            $this->update();
        }
        $this->ownerSlot = $keys->ownerSlot;                  // EVERY trip
        $this->ownerSlot?->setView($this->view);
        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        if ($this->state->order !== null) {
            $keys = new OrderKeys($this->app, $intent);
            $keys->orderId = $this->state->order->id;
        }
    }

    public function onCancel(): void
    {
        try {
            $this->service->cancel($this->state->order?->id);
            $this->state->status = 'cancelled';
            $this->update();                                   // explicit: nothing observes the state
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Cancelling an order', $caught);
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('5f2c9a1e7b30', function (int $event, int $count, array $form): void {
            match ($event) {
                1 => $this->onCancel(),
                default => Skeleton::unknownEvent($event),     // an unknown code must not vanish
            };
        });
    }
}
```

Complete templates (place and route, view state, info DTO + service, presenter, the React
view, wiring, tests) are in [references/patterns.md](references/patterns.md).

## Rules that break silently

1. **Call `$this->update()` after changing the view state.** Nothing observes the state:
   the view is only re-serialized when marked dirty. Forgetting it leaves the screen showing
   the old value until something else happens to dirty it. Calling it twice costs nothing -
   the dirty views are collected and sent once at the end of the request (or by the 50 ms
   background flush).
2. **The view state is a contract with the React view, written in public properties.** The
   serializer writes only PUBLIC, non-static properties; omits `null` and blank strings;
   always writes non-nullable `bool`/`int`/`float` (even `0`); turns a `CubeView` into
   `"<name>Id"`; skips `#[Transient]`. Nested objects are written with their PUBLIC
   properties only - put a domain entity (private fields) in the state and the client gets
   `{}`. Use presentation structs with public fields (`ProductInfo`), built from the entity.
   The React view must default every field that can be omitted.
3. **The `classId` and the event codes are a contract between two files.** The PHP
   `Skeleton('48b693f67410', ...)` and the React `BaseViewClass.FC(ProductViewClass,
   "48b693f67410")` must carry the same id, and every `bridge.submit(vsid, CODE)` in the view
   must have a `match` arm. A wrong id makes the bridge throw `No view registered for
   viewId` (browser console) and the area is not drawn; a missing arm reaches
   `Skeleton::unknownEvent`, which throws `eventCode(N) not handled` - the session logs it
   and shows the unexpected-error alert. Keep the codes as named constants on both sides.
4. **Event arguments come in the form as `p.<name>`; plain fields sync the state.**
   `bridge.setFormField(vsid, "p.quantity", 2)` + `submit` delivers `$form['p.quantity']` to
   the skeleton closure - coerce it (`Coerce::asInt`) and validate. A field without the
   prefix is a state sync, delivered to the skeleton's THIRD argument (`syncState`); without
   it, the value is silently dropped.
5. **Install `$createView` in both places.** The remote host
   (`ShoppingRemoteApplication::installViewFactories`) binds each presenter to a
   `RemoteView`; the test application (`ShoppingApplicationMock::installViewMocks`) binds it
   to a view mock. A new presenter missing from either throws "`$createView` not set".
6. **Read the slot on EVERY `applyParameters`, and fill it.**
   `$keys->ownerSlot` then `->setView($this->view)`. The
   slot is decided by whoever is above; a presenter that survives a trip cannot know whether
   that decision changed, and publishing into the old slot makes the screen disappear or
   appear in the wrong place. The owner, in turn, must offer it on every trip (as
   `RootPresenter` and `HomePresenter` do) - otherwise the child reads `null`.
7. **A presenter that offers a slot clears it when it is the leaf.** When `$deepest`, the
   owner shows its own content (`setContentView(null)`); when not, it offers its slot to the
   next level (`$keys->ownerSlot = $this->contentSlot`). In `release()`, clear
   the view fields that point to children, release the children, remove listeners and cancel
   timers.
8. **Every event handler guards itself.** Wrap the body in `try` and send what is unexpected
   to `$this->app->alertUnexpectedError(...)`; turn the EXPECTED failures (record not found,
   invalid quantity) into an error code + message in the presenter's own state. An uncaught
   exception still reaches the session's alert, but with no context and with the screen
   possibly half-updated.
9. **Parameter and attribute names live in keys classes, unique across the application, each
   with one owner.** A navigation passes the SAME intent through every `applyParameters` from
   root to leaf; two presenters using the same name overwrite each other mid-walk. Each place
   has its own `XxxKeys` - guards and screens without names included - extending the keys of
   the place above, so the class tree mirrors the place tree and a deeper place can READ what
   an ancestor publishes. The file declares a two-letter
   `PREFIX`, unique in the application, and its names as private constants
   `P_X = self::PREFIX . '-code'` (parameters) and `A_X = ...` (attributes), exposed as typed
   properties with `get`/`set` hooks. Nobody else touches the intent by name -
   `KeysNamesTest` enforces it. The only deliberate reuse is the composition slot
   (`RootKeys::$ownerSlot`): each level re-offers it under the same name.
10. **The address is outside input: validate on arrival.** Even signed, an old link brings a
    record that no longer exists or a page beyond the end. The keys getter validates and
    returns a usable value or `null` (ids: non-negative ints; choices: allowlists); the
    presenter turns `null` into a specific exception (`WrongParametersException`, `XxxNotFoundException`) - the navigation
    rolls back and the caller shows the error.
11. **A trip starts from STATE**: `XxxKeys::of($this->app)` builds the intent from
    `newIntent()` (every live presenter publishes), you set what the destination needs and
    call `->go()`. A redirect INSIDE `applyParameters` passes the intent in transit instead
    (`(new XxxKeys($this->app, $intent))->go()`), so what the address just brought is not lost.
    Heavy data already in hand travels as an ATTRIBUTE (an `A_` name in the keys), not as a parameter.
12. **Do not navigate to the place you are already on.** Change your own state, `update()`,
    and - if the change alters what you publish - `$this->app->updateHistory()` so the
    address follows.
13. **Derived state goes in `commitComputedState`.** It runs for each dirty view right
    before serialization. Compare and only `update()` if the value changed (otherwise the
    view stays dirty forever).
14. **Never block the event loop.** One process serves every session: `sleep()`,
    `file_get_contents` on a URL, a blocking HTTP client or a long CPU loop freezes ALL users.
    Wait with the async `sleep` from the context or `$this->app->scheduler()`; use Amp
    clients for I/O; move heavy CPU to a worker (`amphp/parallel`). Database calls are
    already asynchronous.
15. **No mutable static state per request.** A database query suspends the Fiber, and other
    sessions run in the meantime. Per-request context (security context, open transaction)
    lives in a `FiberValue`. A `static` field that holds "the current user" leaks between
    sessions.
16. **Timers belong to the presenter that created them.** Keep the `Registration` returned
    by `scheduler()->schedule(...)` and `remove()` it in `release()`; the same for listeners
    (`addChangeListener` returns a `Registration`). A forgotten timer runs on a released
    presenter.
17. **Passing through the path is not reloading.** A presenter that stays on the path gets
    `applyParameters` with `initialization: false` on every trip that passes through it
    (opening a product inside the home passes through the home). Compare what you have with
    what the intent asks, and only fetch when it changed.
18. **Persistence: always project, never loop.** Every `fetch` carries a projection with only
    what the screen shows; a 1:N relation comes inside the projection (`ProjectionList`, with
    its own criteria and limit), in the same query. A `foreach` that calls a repository per row
    is the N+1 the `JsonQuery` exists to prevent. See
    [references/persistence.md](references/persistence.md).
19. **Several writes = one transaction.** `Beans::of(TransactionService::class)->required(fn () => ...)`. The
    transaction is bound to the current Fiber: work spawned with `async()` inside it runs
    OUTSIDE the transaction.

More items, organized for review: [references/review-checklist.md](references/review-checklist.md).

## Which kind of presenter you are writing

| Kind              | Has a place? | Draws?           | Base class               | When                                                         |
| ----------------- | ------------ | ---------------- | ------------------------ | ------------------------------------------------------------ |
| **Screen**        | yes          | yes              | `AbstractCubePresenter`  | the common case                                              |
| **Slot owner**    | yes          | yes              | `AbstractCubePresenter`  | a screen that hosts the screens below it (the home)          |
| **Guard**         | yes          | no               | `CubePresenter` (direct) | session/role check; redirects and returns `false`            |
| **Child segment** | **no**       | yes, a segment   | `AbstractChildPresenter` | a panel or tab of ONE owner; the owner creates and releases  |

Details and templates in [references/patterns.md](references/patterns.md).

## Workflows

### Create a new screen

1. Decide on which **face** it appears and who is its slot owner (inside what is it?).
2. Add the case to `Place` (id grows with depth; `name()` is the address name;
   `createPresenter` and `go`), and the route method to `Routes`.
3. A keys class next to the presenter, extending the keys of the place above
   (`CartKeys extends HomeKeys`), with `place()`. If it declares names: a NEW two-letter
   prefix, its `P_`/`A_` constants and typed properties that validate on `get`.
   `KeysNamesTest` fails while a place has no keys.
4. Write: view state → info structs + service (with the projection) → presenter (with
   `skeleton()` and a NEW random 12-hex `classId`) → `$createView` in the remote host AND in
   the test mock (+ a view mock class) → the React view with the same `classId` and event
   codes → register it in `frontend/shopping.shell.react/src/scripts/index.tsx`.
5. Test without a browser (`PresentationTestCase`): call the presenter methods the events
   would call, assert the view state of the mocks. Then `bin/build-frontend.sh`, run the
   server, and try it in the browser.

### Change an existing screen

- **Something the address must remember** (id, filter, page): a property in the OWNER's
  keys (validating getter; setter writes `null` for the default), read in `applyParameters`,
  written in `publishParameters`, and after changing it in place call `updateHistory()`.
- **A reader preference** (collapsed panel, column order) does not go in the address: keep
  it in the client storage (`clientPersistentStore()`), read it in `applyParameters`.
- **A new event**: a constant in the React view, `setFormField('p.x', ...)` + `submit`, a
  `match` arm in the skeleton, a guarded `onX` method in the presenter.
- **Take the user to another screen**: `$keys = XxxKeys::of($this->app)` (from the current
  state), set what the destination needs, `$keys->go()`, catching the destination's expected
  exceptions.

### Review Cube code

Go through [references/review-checklist.md](references/review-checklist.md) file by file.
Report each finding with the symptom it produces, not only the rule.

### Diagnose a defect

Start with [references/diagnosis.md](references/diagnosis.md). The most common:

- **Area of the screen stays empty** (often with `No view registered for viewId` in the
  console): `classId` mismatch between skeleton and React view, the view not registered in
  `registerComponents`, or the shell not rebuilt.
- **Screen does not change after an action**: `update()` missing.
- **Field always empty in the view**: it is private/protected, null/blank (omitted), or an
  object without public properties.
- **Whole server slow or frozen**: something blocking the event loop (rule 14).

## To go deeper

- [references/framework.md](references/framework.md) - the engine API, the remote protocol,
  the event loop and Fibers.
- [references/patterns.md](references/patterns.md) - templates: place and route, screen
  module, React view, guard, slot owner, child segment, tests.
- [references/persistence.md](references/persistence.md) - entities, criteria, projection,
  `JsonQuery`, repositories, transactions, security, REST API.
- [references/organization.md](references/organization.md) - where each kind of file lives.
- [references/review-checklist.md](references/review-checklist.md) - review checklist.
- [references/diagnosis.md](references/diagnosis.md) - symptom → cause → fix.
