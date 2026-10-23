# Code organization

How this repository is laid out. A new application built on the framework follows the same
shape as `src/Shopping/`. The idea that ties it together: **the presenter folders mirror the
place tree** - opening a folder shows where the screen is in the cube.

## Contents

1. Top level
2. The framework
3. The application
4. The presentation
5. The view (React shell)
6. Tests
7. Names

---

## 1. Top level

```
bin/            server.php (start), build-frontend.sh (React shell)
docs/           architecture and operations
frontend/       shopping.shell.react (the views), remote.bridge.react (the protocol client)
skills/         this skill
src/Framework/  the framework (no application code)
src/Shopping/   the demo application
tests/          PHPUnit (mirrors src/)
work/           config/ (application.toml + application.local.toml, out of git), data/, log/
```

## 2. The framework

```
src/Framework/
  Cube/            engine: CubeApplication, CubeNavigation, CubeIntent, presenters, slots, Skeleton
    Remote/        remote view, protocol, channel security, client storage, session registry
      Http/        Amp integration: WebSocket endpoint, index.html, session init, web cache
    Util/          QueryString
  Domain/          contracts: Repository, Criteria, Projection, Transaction, Security, Codec, exceptions
  Persistence/     Database (async PostgreSQL), JsonQuery, dialects, AbstractRepository,
                   transactions (local and remote)
  Rest/            base of the REST API (RestRequest, RestHandler, filters)
  Commons/         Beans (services by interface), scheduler, FiberValue, ReentrantLock, Base62, RSA, JSON helpers, log
```

Framework code never imports application code.

## 3. The application

```
src/Shopping/
  Domain/          entities, XxxCriteria, XxxOrderBy, repository interfaces, codecs, roles, config
  Persistence/     repository implementations, schema + seed, authentication, REST controllers
  Presentation/    places, routes, keys, presenters, view states, services
  RemoteHost/      the WebSocket session (installs the RemoteView factories) and its bootstrap
  Server/          the HTTP/WebSocket server and its routes
```

## 4. The presentation

```
Presentation/
  Place.php              the cube: one enum case per place
  Routes.php             one method per face: the chain of places
  StorageKeys.php        client storage keys
  ShoppingApplication.php  the session base: services, subject, cart, alerts
  ShoppingContext.php    what every session shares besides the beans: scheduler, logger, simulation flags
  Exception/             expected navigation failures (XxxNotFound, WrongParameters)
  Presenter/
    RootPresenter.php        place ROOT (+ the alert view)
    RootKeys.php             the base keys: user parameter and the composition slot
    Open/                    place OPEN (public area): OpenKeys, OpenPresenter
      Login/                 LOGIN: keys, presenter, view state, service, result types
    Restricted/              place RESTRICTED (guard): RestrictedKeys, RestrictedPresenter
      Home/                  HOME (slot owner)
        Products/            child segment of the home (panel)
        Purchases/           child segment of the home (panel)
      Cart/                  CART (inside HOME)
      Products/              PRODUCT (inside HOME)
      Receipt/               RECEIPT (inside HOME)
```

A screen folder holds: `XxxKeys.php` (every place has one), `XxxPresenter.php`,
`XxxViewState.php`, the presentation structs it
shows (`XxxInfo.php`, `XxxItem.php`), its service (`XxxService.php`), and its child
segments. What several screens read lives with the screen that owns the subject; the
others import from there.

## 5. The view (React shell)

```
frontend/shopping.shell.react/src/scripts/
  index.tsx                 registerComponents(...) - every view must be listed
  views/<screen>/XxxView.tsx   BaseViewClass.FC(XxxViewClass, "<classId>")
  views/<screen>/xxx-sel.ts    style selectors
  utils/                    formatting, endpoints, BaseViewClass
frontend/remote.bridge.react/   the protocol client (connection, storage, view registry)
```

Build with `bin/build-frontend.sh` (output in `work/frontend/shopping.shell.react/`); during
development `cd frontend/shopping.shell.react && npm run watch`.

## 6. Tests

```
tests/
  Support/TestDatabase.php        the PostgreSQL test database
  Framework/...                   engine, persistence, protocol, commons
  Shopping/Presentation/          PresentationTestCase, view mocks, acceptance tests
    Mock/                         one XxxViewMock per presenter + ShoppingApplicationMock
  Shopping/Persistence/           repositories (RepositoryTestCase)
  Shopping/RemoteHost/            the wire protocol with a fake browser
  Shopping/Server/, Shopping/Rest/   the real server on a free port
```

## 7. Names

- Classes: `XxxPresenter`, `XxxViewState`, `XxxKeys`, `XxxService`, `XxxInfo`, `XxxViewMock`; one class
  per file (PSR-4), file named after the class.
- Places: `UPPER_CASE` enum cases; address names in lowercase (`name()`).
- Parameter and attribute names: private constants in the place's keys, `P_X` / `A_X`, with
  the value `self::PREFIX . '-code'` (`pr-id`, `rc-made`); the two-letter prefix is unique per
  keys file.
- View `classId`: 12 random hex characters, unique, the same in the skeleton and in the React
  view.
- Event codes: small integers, named constants on both sides (`ON_ADD_TO_CART = 2`).
- Comments and documentation in English; diagrams in documentation use Mermaid.
