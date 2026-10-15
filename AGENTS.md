# AGENTS.md

This file provides guidance to AI agents working in this repository.

Writer is a **single-file PHP application**. All application code lives in `index.php`;
data is a SQLite file. There is no framework, no Composer, and no build step.

## Knowledge Base

| Theme | File | Description |
| ----- | ---- |------------- |
| Specification | [docs/SPEC.md](docs/SPEC.md) | Design, data models, API, acceptance criteria |
| Overview | [docs/knowledge/overview.md](docs/knowledge/overview.md) | Project overview and setup |
| Architecture | [docs/knowledge/architecture.md](docs/knowledge/architecture.md) | Request flow, auth, security, data layer, rendering |
| Commands | [docs/knowledge/commands.md](docs/knowledge/commands.md) | Dev server, tests, importer |
| Routes | [docs/knowledge/routes.md](docs/knowledge/routes.md) | Web pages and API endpoints |
| Settings | [docs/knowledge/settings.md](docs/knowledge/settings.md) | Configuration fields and environment variables |
| Deployment | [docs/knowledge/deployment.md](docs/knowledge/deployment.md) | Apache/Nginx + PHP-FPM, Supervisor |
| Testing | [docs/knowledge/testing.md](docs/knowledge/testing.md) | `tests.php` layout and helpers |
| Status | [docs/knowledge/checkpoints.md](docs/knowledge/checkpoints.md) | Current project status |
| Memory | [docs/memory/](docs/memory/) | Dated session fragments |

## Commands

- `just start` — dev server on port 8080 (foreground)
- `just start 3000` — dev server on a custom port
- `just start background` — dev server in the background
- `just stop` — stop the background server
- `just test` — run `tests.php` (self-hosted server on port 8091, temp DB)
- `php -S localhost:8080 index.php` — run without `just`
- `WRITER_DB_PATH=… php import.php` — one-time JSON→SQLite importer (gitignored)

## Architecture

- **`index.php`** — the entire app: guard, config, database, security, helpers,
  Markdown parser, HTML sanitizer, API, web pages, views, CSS and JS.
- **`tests.php`** — HTTP + unit suite. Starts its own `php -S` on port 8091 with
  `test.sqlite`; never touches `writer.sqlite`.
- **`import.php`** — transitional importer. It reads `storage/data/*.json` (the store from
  the previous Node deployment, dropped in place for the migration). CLI-only,
  gitignored, and blocked over HTTP by the built-in-server guard and `.htaccess`
  (deny it explicitly under Nginx).
- **`.htaccess`** — Apache front controller + sensitive-file blocking.
- Section banners in `index.php` use `// =====` headers; naming is camelCase
  (`renderPage()`, `viewFooter()`, `cssInline()`), Allman braces.
- Routing: a small `route($method, $pattern, $handler)` table in `dispatch()`.
  Patterns are anchored PCRE (`#^/api/posts/([^/]+)$#`).
- Database migrations use `PRAGMA user_version`; new steps go in `migrateDatabase()`
  behind a `if ($version < N)` guard.

## Authentication

- **API** — `X-Auth-Token` header, compared with `hash_equals`.
- **Web** — a session cookie holding the same auth token (`$_SESSION['authToken']`).
  Login accepts username/password **or** the auth token **or** the recovery key.
- Passwords are bcrypt; legacy `sha256` and `$scrypt$` hashes are verified and
  rehashed on login.

## Testing

Tests run against a real server with a temporary SQLite DB and clean up after
themselves. `php tests.php` (or `just test`). Every user-facing change should keep the
suite green.

## Style

- Match the patterns in `index.php`. No abstractions, no premature optimization.
- No inline CSS in templates — styles live in `cssInline()`.
- Escape output with `h()`; JSON responses with `jsonResponse()`.
- Keep the single-file constraint: do not split code into new files.

## Constraints

- Single-file architecture is intentional. Do not add files.
- No external PHP dependencies. No Composer, no autoloaders.
- Surgical changes only. One thing per change.
