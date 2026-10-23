# WDC Cube PHP

**Cube MVP** in PHP: an architecture in which the *presenters* — the logic behind each screen — run
on a long-lived PHP server, and the client only draws the state it receives. The bundled client is
a React shell that talks to the server over WebSocket.

The repository contains the framework and a complete demo application, **Store**.

- [docs/guide-for-php-developers.md](docs/guide-for-php-developers.md) — start here if you write
  PHP with template frameworks (Laravel, Symfony, Twig): the idea explained from what you already
  know, with a guided exercise.
- [docs/dev/](docs/dev/README.md) — step-by-step development guides: local setup, building a
  feature from database to screen, repositories, queries, services, transactions, presenters and views.
- [docs/architecture.md](docs/architecture.md) — how Cube works: places, presenters, navigation,
  protocol, N+1-free persistence, security.
- [docs/scaling.md](docs/scaling.md) — multiple instances behind a load balancer.

## Store

A simple shop, end to end:

- **login** via HMAC challenge (the password never goes over the wire), with "remember me";
- product **catalog** with image and description;
- **cart**: add, change quantity, remove, buy;
- purchase **receipt** and the user's purchase **history**;
- **REST API** with JWT, role-based permissions and remote transactions.

Demo users (password = login): `admin` (administrator, sees everyone's data), `john` and
`jane` (customers).

## Requirements

- PHP 8.4+ with the `gmp`, `openssl` and `pgsql` extensions
- PostgreSQL 14+
- Composer 2
- Node.js 20+ (only to build the React shell)

On macOS: `brew install php composer node postgresql@17` (Homebrew's PHP already includes `pgsql`).

## Build and run

Before the first start, put the PostgreSQL username and password in
`work/config/application.local.toml` (not in git):

```toml
[database]
username = "postgres"
password = "..."
```

```bash
composer install          # PHP dependencies
bin/build-frontend.sh     # builds the React shell into work/frontend/
php bin/server.php        # starts the server at http://localhost:8080 (Ctrl+C stops it)
```

Open http://localhost:8080/ (it redirects to the React shell) and log in with `admin`/`admin`.

To work on the frontend with automatic rebuilds:
`(cd frontend/store.shell.react && npm run watch)`.

## Tests

The tests use a PostgreSQL database of their own — **it is dropped and recreated on every run**, so
never use the server's database. Configure it in `application.local.toml`:

```toml
[test.database]
url = "pgsql:host=localhost;port=5432;dbname=wedocode_store_php_test"
username = "postgres"
password = "..."
```

```bash
composer check            # PHPStan (level 8) + PHPUnit
```

In CI, the `WDC_TEST_DB_URL`, `WDC_TEST_DB_USER` and `WDC_TEST_DB_PASSWORD` variables replace the file.

The tests cover the engine, persistence, the presenters (acceptance tests with fake views), the
remote protocol, the REST API and the server end to end (HTTP and WebSocket on a free port).
The server test that uses the React shell is skipped if the frontend has not been built.

## Configuration

Main file: [work/config/application.toml](work/config/application.toml). Local overrides, not in
git: `work/config/application.local.toml`, in the same folder.

On the first start, the server creates the database (if the user has permission), the schema and
the demo data, and generates development keys in `application.local.toml`. **Replace them in
production**, along with `security.jwt.secret`.

Environment variables: `SERVER_PORT`, `LOG_LEVEL` (`debug`, `info`, ...) and `STORE_CONFIG_FILE`
(a different configuration file).

## Database

PostgreSQL, accessed asynchronously (`amphp/postgres`): a query suspends only the session that
issued it, and several queries run at the same time, each on a pooled connection. Under `[database]`:

- `url` — address, default `pgsql:host=localhost;port=5432;dbname=wedocode_store_php`;
- `username`, `password` — in `application.local.toml`;
- `pool.maxConnections` — pool size (default 16).

If the database does not exist, the server tries to create it; the schema and the demo data are
created on the first start.

## REST API

At the root and under each frontend (`/<frontend>/api/...`):

- `GET /api/auth/challenge`, `POST /api/auth/{login,refresh,logout}` — HMAC challenge login, JWT
- `POST /api/repo/<user|product|purchase|purchase-item>/<insert|update|delete|count|fetch|fetch-page|fetch-by-id>`,
  `GET /api/repo/<entity>/{id}`, `GET|PUT /api/repo/product/{id}/image`
- `POST /api/tx/{begin,commit,rollback}`, `GET /api/tx/status` — remote transaction (`X-Tx-Id` header)

With `security.jwt.secret` set, `/api/repo` and `/api/tx` require `Authorization: Bearer <jwt>`;
without it, security is off. The remote transaction limits live in
`[database] remoteTransaction.*`.

Example — count the products priced 20 or more, after obtaining a token:

```bash
curl -X POST localhost:8080/api/repo/product/count \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"price": {"p": [{"o": "GE", "v": [20.0]}]}}'
```

## Layout

- `src/Framework/` — the Cube framework: engine, remote protocol, persistence, REST API
- `src/Store/` — the demo application: domain, persistence, presentation, server
- `frontend/store.shell.react/` — the React shell (React, TypeScript, MUI)
- `frontend/remote.bridge.react/` — the bridge: the client side of the Cube protocol
- `tests/` — PHPUnit
- `bin/` — server and frontend build
- `skills/cube-architecture-php/` — a skill for coding assistants (Claude Code): how to write, review and debug code in this architecture
- `work/` — configuration and runtime data

## License

[MIT](LICENSE).
