# Deployment

Writer is a single PHP file plus a SQLite database. Serve it behind Apache or Nginx with
PHP-FPM, or with PHP's built-in server behind a process manager.

## Database location

Keep the database outside the web root and point `WRITER_DB_PATH` at it:

```bash
# PHP built-in server (dev)
WRITER_DB_PATH=/path/to/data/writer.sqlite php -S localhost:8080 index.php

# Apache (.htaccess or vhost)
SetEnv WRITER_DB_PATH /var/data/writer.sqlite

# Nginx + PHP-FPM
fastcgi_param WRITER_DB_PATH /var/data/writer.sqlite;

# systemd / Supervisor
Environment=WRITER_DB_PATH=/var/data/writer.sqlite
```

The application blocks `.sqlite`, `.env` and `.git` paths internally; your web server
should also deny them as a second layer.

## Apache

The included `.htaccess` routes requests through `index.php` and blocks database and
sensitive files (`tests.php`, `import.php`). It needs `mod_rewrite` and
`AllowOverride All`.

## Nginx

```nginx
location ~ \.(sqlite3?(-(wal|shm))?|db|sql|env|bak|log)$ { deny all; }
location ~ /\.git { deny all; }
location = /tests.php { return 404; }
location = /import.php { return 404; }

location / {
    try_files $uri /index.php$is_args$args;
}
```

## Process manager

Prefer Apache/Nginx + PHP-FPM: the web server already supervises PHP, so no separate
process manager is required.

If you run Writer with PHP's built-in server on the host (for example a small appliance
without php-fpm), use [Supervisor](http://supervisord.org/). This replaces the PM2 setup
used by the previous Node version. Run one `[program:]` per blog instance; a
ready-to-edit sample lives in [`docs/supervisord.sample.conf`](../supervisord.sample.conf):

```bash
sudo cp docs/supervisord.sample.conf /etc/supervisor/conf.d/writer.conf
sudo mkdir -p /var/log/writer && sudo chown admin /var/log/writer
sudo supervisorctl reread && sudo supervisorctl update
```

The host needs PHP 8.1+ with `pdo_sqlite` (FTS5), `gd` (FreeType), `sodium` and
`mbstring`. Set `opcache.enable_cli=1` in the PHP CLI ini. The built-in server is
single-threaded; `PHP_CLI_SERVER_WORKERS` adds workers. `bare` restarts each program
through `startScript` (`sudo -n supervisorctl restart <program> && curl … /api/health`).

## `bare.config.json`

Deployment uses [bare](https://github.com/abtz-Labs/bare). `bare.config.json` lists:

- **Servers** — `raspberrypi.local` (user `admin`, SSH port 22), one entry per blog
  instance, each with its own `deployTo`, `webroot` (`<deployTo>/public_html`) and `type`
  (`php`). `WRITER_DB_PATH` and `WRITER_OG_DIR` are set in the Supervisor program's
  `environment=` (see [`docs/supervisord.sample.conf`](../supervisord.sample.conf)),
  pointing at `<deployTo>/shared/` so the database and OG cache survive deploys.
- **`startScript`** — restarts the instance's Supervisor program, then checks
  `http://127.0.0.1:PORT/api/health` with a retry.
- **`keepReleases`** — 5.
- **`ignore`** — dev-only files (`docs/*`, `tests.php`, `import.php`, `Justfile`,
  `package.json`, `LICENSE`, databases, `storage/*`, `og-images/*`, `.git/*`,
  `bare.config.json`).

## Migrating from the previous Node version

1. Back up `storage/data/` from the old deployment.
2. Copy `import.php` to the server and run it with the target `WRITER_DB_PATH`.
3. Point the vhost at the new `index.php` and set `WRITER_DB_PATH`.
4. Verify `GET /api/health`, log in, and check that old slugs resolve.
5. Retire the PM2 apps.
