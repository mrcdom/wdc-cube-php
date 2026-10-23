# Review checklist

Go through it by file type. For each finding, write the SYMPTOM it produces - that is what
makes the author understand why it matters. Mark as a defect only what has a symptom; the
rest is a suggestion.

## Places, routes and names

- [ ] New place: enum case with an id deeper than its parent, `name()`, `createPresenter`,
      `go`, a `Routes` method with the full chain, and its `XxxKeys` extending the parent
      place's keys. *Symptom: F5 on the new address opens
      the root; "unhandled match case" on navigation.*
- [ ] New name declared in the OWNER's keys (the presenter that publishes it) as a `P_`/`A_`
      constant `self::PREFIX . '-code'`; a new keys file gets a prefix that does not exist yet.
      *Symptom: one screen adopts another's value after a trip.*
- [ ] The keys getter validates (non-negative id or `null`, allowlist, range) and the setter
      writes `null` for the default. *Symptom: an old or edited link opens a broken screen; a
      polluted address.*
- [ ] Only the composition slot (`RootKeys::$ownerSlot`) is reused, on purpose.
- [ ] No `$intent->getParameter*/setParameter/getAttribute/...` outside the keys
      (`KeysNamesTest` fails).
- [ ] Keys assigned to a variable before writing a property (not `(new XxxKeys(...))->x = ...`).

## View state (`*ViewState.php`)

- [ ] Every field the view reads is PUBLIC. *Symptom: the field never reaches the client.*
- [ ] Nested values are presentation structs with public fields, not domain entities.
      *Symptom: the client gets `{}`.*
- [ ] Values arrive ready to show (formatted, defaulted); rules another view would repeat are
      in the presenter or the struct.
- [ ] Fields that are not for the client are `#[Transient]` (or not in the state at all).
- [ ] Children's views are `?CubeView` fields (sent as `<name>Id`).

## Presenter (`*Presenter.php`)

- [ ] `$createView` used with a clear "not set" exception, and installed in the remote host
      AND the test mock. *Symptom: LogicException on the first visit in production or in tests.*
- [ ] `update()` after every change to the view state. *Symptom: the screen does not change.*
- [ ] View, children, listeners created inside `if ($initialization)`.
- [ ] Slot read on every `applyParameters` and filled with `setView($this->view)`.
      *Symptom: after a trip that changes the composition above, the screen disappears or
      shows in the wrong place.*
- [ ] Slot owner: clears its content when `$deepest`, offers its slot otherwise.
- [ ] Parameters validated on arrival; missing/invalid → specific exception.
      *Symptom: an old link opens a broken screen or a 500.*
- [ ] `publishParameters` writes everything that must survive F5, and only what it owns.
      *Symptom: F5 loses the record/page.*
- [ ] Passing through does not reload: compares what it has with what the intent asks.
      *Symptom: opening a child screen reloads the owner's data every time.*
- [ ] Every event handler wraps its body: expected failures → error code in its state;
      unexpected → `alertUnexpectedError`. *Symptom: an error with no context, or a screen
      half-updated.*
- [ ] Navigation from an event goes through the destination's keys (`XxxKeys::of($this->app)`, set what it needs, `->go()`) and catches the destination's
      expected exceptions. *Symptom: the error of the destination becomes a generic alert.*
- [ ] Change in its own place: state + `update()` + `updateHistory()` (if published), no
      navigation to itself.
- [ ] Derived state in `commitComputedState`, with compare-before-update.
      *Symptom: the view is re-sent on every flush.*
- [ ] `release()` releases children, clears child view fields, removes listeners and timers,
      and calls `parent::release()`. *Symptom: a timer firing on a released presenter; a
      released panel still drawn.*
- [ ] Skeleton: a fresh 12-hex `classId`; named constants for event codes; every code the view
      sends has a `match` arm; `default => Skeleton::unknownEvent($event)`; arguments read
      from `p.` fields and coerced.
- [ ] Nothing blocking: no `sleep()`, no synchronous network or file I/O on the hot path, no
      long CPU loop. *Symptom: every user freezes while one request runs.*
- [ ] No static mutable state per request; per-request context in a `FiberValue`.
      *Symptom: one user's data appearing in another session.*

## Child segment (`AbstractChildPresenter`)

- [ ] Created and released by its owner; its view assigned to a state field once.
- [ ] Navigation delegated to the owner.
- [ ] State that must survive F5 is a parameter of the OWNER.

## Persistence

- [ ] Projection on every screen query; relations inside the projection, not in a loop.
      *Symptom: N+1, slow lists.*
- [ ] ORDER BY with an id tie-break; default order is by id.
- [ ] Table, sequence and column names as public constants of the owning repository
      (`self::TABLE`, `self::ID`, `Sql::alias($a)` for aliased columns); no column name as a
      string literal. *Symptom: a typo caught only when the query runs, not by PHPStan.*
- [ ] A service looks up its repositories / `TransactionService` with
      `Beans::of(XxxInterface::class)` in its constructor; the presenter does `new XxxService()`. A new repository has its
      `Beans::set` in `ShoppingRepositories::register()`. *Symptom: "Nenhuma implementação
      registrada" at runtime.*
- [ ] Multi-write operations in `Beans::of(TransactionService::class)->required(...)`.
- [ ] REST: owner scope enforced for users without `data:all`.
- See [persistence.md](persistence.md), section 12.

## React view

- [ ] `BaseViewClass.FC(View, "<classId>")` with the SAME id as the skeleton, and the view in
      `registerComponents`. *Symptom: empty area.*
- [ ] Event codes equal to the skeleton's; arguments sent as `p.<name>`.
- [ ] Every omittable field (null/blank on the server) has a default.
- [ ] Only draws: no formatting or rules another view would repeat. *Symptom: the same value
      written two ways.*
- [ ] Address-worthy state (record, page, tab) comes from the view state, not local state.
      *Symptom: F5 and Back lose it.*

## Tests

- [ ] An acceptance test for the new path (`PresentationTestCase`), including the failure path
      (unknown record ⇒ error code, screen unchanged).
- [ ] View mock class for each new presenter.
- [ ] Timers driven by `ManualScheduler::advance`, not by waiting.
- [ ] `composer check` green (PHPStan level 8 + PHPUnit).
