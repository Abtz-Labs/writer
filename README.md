# Writer

A single-file, self-hosted blog with a RESTful API, built with PHP and SQLite.

No frameworks. No Composer. No build tools. One file, one database, done.

## Concept

Writer is a small, Medium-style blog that keeps everything in one place. The whole
application — routing, API, data layer, Markdown rendering, HTML, CSS and the admin
editor — lives in a single `index.php`. The database is a SQLite file created on first
run. Drop the file on any server with PHP 8.1+ and you have a blog with a scriptable API.

Writer is part of the [Use Bare](https://usebare.org) family: single-file apps with no
build step and no package manager.

## Features

- **Markdown posts** with automatic metadata inference (slug, excerpt, keywords, meta
  description, reading time)
- **RESTful API** with token auth and self-discoverable docs at `GET /api`
- **Admin panel** at `/panel` — create, edit, delete and search posts
- **Settings** at `/settings` — blog info, credentials, custom scripts, token rotation
- **Rich editor** — Tiptap + Turndown loaded from a CDN, stored as Markdown
- **Search** — SQLite FTS5 (trigram) ranked with bm25
- **Open Graph images** — 1200×630 social cards rendered on the fly with GD
- **RSS feed** at `/feed.xml`
- **GitHub Gist embeds** — rendered server-side, highlighted with highlight.js
- **Tag filtering**, pagination and a responsive topic list
- **Draft / published** workflow (drafts are hidden from the public API)
- **Security** — bcrypt hashing, CSRF tokens, login rate limiting, timing-safe
  comparisons, secure cookies, an auto-generated `Content-Security-Policy`, and a
  recovery key

## Requirements

- PHP 8.1 or later
- `pdo_sqlite` with the **FTS5** extension (usually bundled with PHP)
- `gd` with FreeType (Open Graph images)
- `curl` (Gist fetching; falls back to `file_get_contents`)
- `mbstring`
- `sodium` (only to verify legacy `$scrypt$` password hashes)

## Installation

1. Copy `index.php` (and `assets/fonts/`) to your web root, or to any directory served
   by PHP.
2. Make sure the directory is writable, or point `WRITER_DB_PATH` at a writable
   location (recommended — keep the database outside the web root).
3. Open it in a browser. The setup screen asks for the blog title, author, and admin
   credentials. Save the auth token and recovery key it shows — they are shown once.

For development (requires [just](https://github.com/casey/just)):

```
just start
```

With a custom port, in the background, or both:

```
just start 3000
just start background
just start 3000 background
just stop
```

Or directly:

```
php -S localhost:8080 index.php
```

## Configuration

Writer reads one environment variable:

| Variable          | Default                     | Purpose                                        |
| ----------------- | --------------------------- | ---------------------------------------------- |
| `WRITER_DB_PATH`  | `writer.sqlite` beside the app | Path to the SQLite database file            |

Example:

```bash
WRITER_DB_PATH=/var/data/writer.sqlite php -S localhost:8080 index.php
```

## Admin Panel

Log in at `/login` with the username/password set during onboarding, or with the auth
token. `/panel` lists every post (drafts included) with search and pagination. Opening a
post (or `?slug=<slug>`) loads it into the editor. Deleting a post asks for confirmation.

## Settings

`/settings` has five cards:

- **General** — blog title, author, description, "show API Docs link" toggle
- **Credentials** — username and password
- **About the Author** — a short bio shown in the sidebar (max 280 chars)
- **Custom Scripts** — arbitrary tags injected into `<head>`, plus the CSP domain allow-list
- **Security** — rotate the auth token (two-step confirmation)

## API Usage

```bash
# Create a post (defaults to a draft)
curl -X POST http://localhost:8080/api/posts \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"title": "Hello World", "body": "My first post", "status": "published"}'

# List published posts
curl "http://localhost:8080/api/posts?page=1&limit=10"

# Get a single post by slug
curl http://localhost:8080/api/posts/hello-world
```

Destructive actions (`DELETE /api/posts/:slug`, `POST /api/settings/rotate-token`) return
`202` with a `confirmation_url`; send an authenticated `POST` to that URL to proceed.

The full reference is served in-app at `/docs` and at `GET /api`.

## Health Check

```bash
curl -sf https://your-host/api/health
```

Returns `200` with `{"status":"ok","timestamp":"…"}`. It performs no database query; it is
a liveness probe for the process and routing.

## Importing an existing database

Writer can import the JSON store used by the original Node version. Copy that store into
`storage/data/` and run the importer:
The importer is a one-time script and is **not** deployed:

```bash
WRITER_DB_PATH=/path/to/writer.sqlite php import.php
```

It prints a dry-run count, inserts posts, settings and confirmations, rebuilds the FTS
index, and prints a summary. `import.php` is gitignored. It is CLI-only and is blocked
over HTTP by the built-in-server guard and by Apache (`.htaccess`); if you deploy behind
Nginx, deny it explicitly (see below).

## Deployment

For production, serve Writer behind Apache or Nginx with PHP-FPM.

- **Database location.** Keep `writer.sqlite` outside the web root and set
  `WRITER_DB_PATH` in the vhost, `.htaccess` (`SetEnv`), or FastCGI params.
- **Deny direct file access.** Writer blocks `.sqlite`, `.env` and `.git` paths
  internally; your web server should enforce it too.
- **Do not deploy `tests.php` or `import.php`.** Both are development-only.

### Apache

The included `.htaccess` routes requests through `index.php` and blocks database and
sensitive files. It needs `mod_rewrite` and `AllowOverride All`.

### Nginx

```nginx
location ~ \.(sqlite3?(-(wal|shm))?|db|sql|env|bak|log)$ { deny all; }
location ~ /\.git { deny all; }
location = /tests.php { return 404; }
location = /import.php { return 404; }

location / {
    try_files $uri /index.php$is_args$args;
}
```

### Process manager

Writer is a plain PHP app, so Apache/Nginx + PHP-FPM is enough — no process manager
needed. If you run it with PHP's built-in server (for example on a small box with no
PHP-FPM), use [Supervisor](http://supervisord.org/) instead of PM2. A ready-to-edit
config is included at [`docs/supervisord.sample.conf`](docs/supervisord.sample.conf):

```bash
sudo cp docs/supervisord.sample.conf /etc/supervisor/conf.d/writer.conf
sudo supervisorctl reread && sudo supervisorctl update
```

## Running Tests

The suite starts its own server on port 8091 with a temporary database and cleans up
after itself:

```
just test
```

Or directly:

```
php tests.php
```

## Contributing

Please keep these principles in mind:

1. **Single-file architecture.** All application code stays in `index.php`. This is a
   deliberate constraint, not a limitation to work around.
2. **No external PHP dependencies.** No Composer, no autoloaders. Implement what you
   need directly.
3. **Write tests.** New features include tests in `tests.php`. Use TDD when possible.
4. **Keep it simple.** If 50 straightforward lines solve the problem, don't write 200
   abstracted ones. Match the existing style.
5. **Surgical changes.** One change per commit. Don't bundle unrelated cleanups.

## License

[O'SAASy](https://osaasy.dev/). See [LICENSE](LICENSE) for details.

In short: use it, modify it, self-host it, distribute it. Just don't offer it as a
competing hosted service where the software itself is the primary value.

### Third-party assets

- `assets/fonts/DejaVuSans.ttf` and `DejaVuSans-Bold.ttf` are used to render Open
  Graph images. They are released under the Bitstream Vera / DejaVu license (free to
  use and redistribute).

Designed and built by [Rogerio Taques](https://x.com/rogeriotaques), the guy behind
[Abtz Labs](https://abtz.co).
