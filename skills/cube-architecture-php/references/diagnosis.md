# Diagnosis: symptom → cause → fix

In Cube, the cause is rarely where the symptom shows up. Use the table to know where to look
first, and confirm the hypothesis before changing code.

| Symptom | Likely cause | Where to look / fix |
|---|---|---|
| An area of the screen stays empty; `Nenhuma view registrada para a viewId` in the browser console | `classId` differs between the PHP skeleton and `BaseViewClass.FC(..., "<id>")`; view missing from `registerComponents`; shell not rebuilt | Compare the ids; check `index.tsx`; `bin/build-frontend.sh` and reload |
| The screen does not change after an action that changed the state | `update()` missing | Add `$this->update()` after the change |
| A field is always empty in the view | Property not public; value null or blank (omitted); nested object without public properties (an entity) | Make it public; default it in the React view; use a presentation struct |
| `eventCode(N) not handled` in the log + alert | The view sends a code the skeleton does not handle | Add the `match` arm; keep codes as named constants on both sides |
| A button does nothing, no alert | The view never calls `bridge.submit`, or the handler returns early (validation) without showing an error | Check the React emitter; make the early return set an error code |
| An event argument always arrives null | Sent without the `p.` prefix (it became a state sync), or not coerced | `setFormField(vsid, "p.x", v)`; `Coerce::asInt($form['p.x'] ?? null)` |
| A typed value is lost (plain field) | No `syncState` closure in the skeleton | Add the third argument to `Skeleton` |
| `XxxPresenter::$createView not set` | Factory not installed in the remote host or in the test mock | `installViewFactories` / `installViewMocks` |
| The screen opens in the wrong place, or disappears after some trips | Slot read only at initialization; owner did not re-offer it | Read `$keys->ownerSlot` every call; owner sets `$keys->ownerSlot` when not deepest |
| A child screen stays drawn after leaving | The owner did not clear its content when it became the leaf, or did not release the child | `setContentView(null)` when `$deepest`; release children |
| F5 or Back loses the record/page/tab | Not published, or published by a presenter that is not the owner | A property in the owner's keys; `publishParameters` writes it, `applyParameters` reads it |
| `Cannot use temporary expression in write context` | Writing a keys property on `(new XxxKeys(...))` directly | Assign the keys to a variable first |
| `KeysNamesTest` fails | A name outside the keys' `P_`/`A_` table, a repeated prefix, or `$intent->...('literal')` in a presenter | Move the name into the owner's keys |
| The address does not follow an in-place change | `updateHistory()` missing | Call it after changing published state |
| An address edited by hand "does nothing" | Expected: the signature does not match, the server reaffirms the current state | Navigate through the application; do not hand-edit addresses |
| Opening a record that does not exist shows a generic alert | The caller does not catch the destination's specific exception | Catch `XxxNotFoundException` in the event handler and set an error code |
| After a failed navigation the screen is half-changed | State changed before the navigation and not restored; or state changed in `applyParameters` before the throwing load | Load first, assign after; let the rollback restore the presenters |
| The view is re-sent every 50 ms | `commitComputedState` calls `update()` without comparing | Compare before `update()` |
| A timer acts on a screen that is gone | `Registration` not removed in `release()` | Keep and `remove()` it |
| Every user freezes while one request runs | Blocking call on the event loop: `sleep()`, synchronous HTTP/file I/O, long CPU loop | Async sleep (`context->sleep`), Amp clients, `amphp/parallel` for CPU |
| One user sees another user's data | Request context kept in a static field | `FiberValue`; `CurrentSecurityContext` is already per Fiber |
| Part of an operation saved after an error | Writes outside a transaction, or done in an `async()` spawned inside it | `tx()->required(...)`; no `async()` for work that must be atomic |
| Lists slow, latency grows with the number of rows | N+1: repository called per row | Put the relation in the projection (`ProjectionList`) |
| Rows repeated or missing across pages | ORDER BY without a unique tie-break | End the ordering with the id |
| Session lost after a reconnect | The session expired (`server.sessionTtlSeconds`) or the reconnect reached another server instance | A new session is rebuilt from the address and client storage; keep important transient state in storage or the database |
| Tests fail with "Banco de testes não configurado" | `[test.database]` missing in `application.local.toml` | Configure it (a database used only by tests) |

## Procedure when nothing in the table matches

1. Reproduce it in an acceptance test (`PresentationTestCase`): call the presenter methods the
   events would call and assert the mocks' state. If the test reproduces it, the defect is in
   the presentation; if not, it is in the React view, the build or the protocol.
2. Protocol level: `tests/Shopping/RemoteHost/RemoteProtocolTest` drives a session through
   the real wire format - reproduce the message sequence there.
3. Follow the cycle: which presenter received `applyParameters` with which `initialization`
   and `deepest`, who returned `false`, who threw, who was released.
4. Only then read the engine (`src/Framework/Cube/CubeNavigation.php`,
   `CubeApplication.php`, `Remote/RemoteApplicationSupport.php`).
