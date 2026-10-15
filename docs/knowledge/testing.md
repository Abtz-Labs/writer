# Testing

`tests.php` is a self-contained suite. It defines `WRITER_TESTING` (so `index.php` acts as
a library), starts its own `php -S` server on **port 8091** with a temporary `test.sqlite`,
runs the groups, shuts the server down, and prints a colored summary.

Run it with:

```
just test
# or
php tests.php
```

## Layout

- **Helpers** — `req()` (curl wrapper returning status, headers and body), `newSession()`,
  `csrfFrom()`, `webLogin()`, and the `assert*` functions.
- **Unit groups** run in-process against the real functions (database, Markdown, metadata,
  auth).
- **HTTP groups** run against the child server, exercising routing, headers, forms and the
  JSON API.

Groups cover: health and security headers, sensitive-file blocking, schema migrations, the
FTS index, Markdown rendering, the sanitizer, metadata, the API (docs, auth, posts,
settings, confirmations), password verification, the recovery key, every web page, the
rate limiter, session rotation, RSS, OG images, Gist rendering, the panel, the settings UI,
and the importer.

## Notes

- The suite never touches `writer.sqlite`; it works on `test.sqlite`, which is deleted at
  the start and created fresh.
- The importer group runs `php import.php` against a temporary database and skips when
  `import.php` or `storage/data/` is absent (they are development-only).
- `PHP`'s stat cache persists for the life of the process. A test that checks for a file an
  out-of-process server deleted or created must call `clearstatcache()` first.
- CSS behaviour (the mobile topic collapse, the editor) is verified in a browser, not in
  the suite; the suite asserts the rendered HTML contract.
