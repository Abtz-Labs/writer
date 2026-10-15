# Commands

## Development

```bash
just start              # dev server on port 8080 (foreground)
just start 3000         # dev server on a custom port
just start background   # dev server in the background (writes .server.pid)
just stop               # stop the background server
```

Without `just`:

```bash
php -S localhost:8080 index.php
WRITER_DB_PATH=/var/data/writer.sqlite php -S localhost:8080 index.php
```

## Testing

```bash
just test               # run the suite
php tests.php           # run the suite directly
```

The suite spins up its own server on port 8091 with a temporary database.

## Linting

```bash
php -l index.php
php -l tests.php
```

## Importer

```bash
WRITER_DB_PATH=/path/to/writer.sqlite php import.php
```

One-time import of `storage/data/*.json` (the original Node store). Prints a dry-run count
and a summary, and rebuilds the FTS index. `import.php` is gitignored and CLI-only.

## Deployment

See [Deployment](deployment.md). Configuration is in `bare.config.json`.
