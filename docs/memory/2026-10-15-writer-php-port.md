# 2026-10-15 — Writer → PHP port (Use Bare)

## Outcome

`index.php` is the whole app (single file). Node/Express/EJS/JSLiteDB removed.
`php tests.php` green; `php tools/parity.php` was 92/92 before the parity tooling was
deleted with the Node tree.

## Key decisions

- **Single file** `index.php`: guard → CONFIG → DATABASE → SECURITY → HELPERS → METADATA
  → MARKDOWN → SANITIZER → RSS → GIST → OG IMAGES → WEB: STYLES → WEB: RENDER → WEB: PAGES
  → ROUTER → API → BOOTSTRAP. camelCase, `// =====` banners, Allman braces.
- **SQLite FTS5 `tokenize='trigram'`** + bm25 over title/slug/tags/keywords/excerpt/body,
  kept in sync by `'delete'`-command triggers. One `'rebuild'` in the importer; no boot
  rebuild. Terms under 3 chars fall back to a title `LIKE`.
- **Auth**: bcrypt for new passwords; legacy sha256 and `$scrypt$` (sodium) verify and
  rehash on login. Session cookie carries the auth token; `hash_equals` everywhere.
  Recovery key (sha256-hashed, rotates on use). Login/onboarding rate limits.
- **Sanitizer** is a faithful tokenizer port of the `xss` package (no `ext-dom`), keeping
  `<script src>` only for `gist.github.com`.
- **Markdown** is a pure-PHP GFM-subset parser tuned to `marked`, not strict CommonMark.
- **OG images**: GD + FreeType, 1200×630, bundled `DejaVuSans` + `DejaVuSans-Bold`,
  file cache in `og-images/`, invalidated on post update/delete and settings changes.
- **Gists** render server-side; code is highlighted client-side by highlight.js from
  jsdelivr.
- **Panel editor**: Tiptap + Turndown from esm.sh, stored as Markdown.
- **License**: O'SAASy (same as uptime/tasssks). `package.json` kept as a version mirror.

## Deliberate deviations

- Search ranks by bm25 relevance, not the Node fuzzy scorer / date re-sort.
- Onboarding shows the one-time recovery key.
- The settings page displays the credentials-mismatch error Node threaded but never showed.
- The plan's "Use Bare" footer credit was **removed by the owner** — the footer keeps only
  "Powered by Writer". The plan's `storage/data` archive was also removed (see Cleanup notes).
- Before setup, every human-facing page redirects to `/onboarding`
  (`/api*`, `/feed.xml`, `/og/*` stay reachable).

## Notes / gotchas

- PHP's **stat cache** outlives a single request in a long-running test process: call
  `clearstatcache()` before `is_file()` on a path another process touched.
- The "Sensitive Files" test must **restore** a pre-existing `import.php` (it writes a
  temp one to prove the `404`) — it must not delete the real importer.
- `$http_response_header` (PHP 8.5) and `imagedestroy()` are deprecated; both were removed.
- `Sensitive Files` / CLI-server guard mirrors `.htaccess`; the `.htaccess` rewrite rules
  must not use a leading `/` in the pattern (per-directory context strips it).

## Known Markdown edge cases (unfixed)

`***bold italic***`, strong inside emphasis (`*a **b** c*`), and single-tilde
strikethrough differ from strict CommonMark. `marked` itself deviates, and the corpus does
not exercise these, so they were left as-is rather than risk the parity-tuned parser.

## Cleanup notes

- The Node tree was removed by request (see the plan's file map).
- `storage/data/` (the old JSLiteDB JSON store, gitignored) was removed by the owner. The
  `Importer` test group detects its absence and SKIPs, so the suite stays green. To migrate
  a live Node deployment, copy its `storage/data/` into place and run `import.php`.
- `writer.sqlite` (gitignored) holds the imported corpus (92 posts).

## Review pass (four subagents) and fixes

Reviewers: security/correctness, Use Bare conventions, tests, docs/config.

Fixed:
- **Blocker — sanitizer script bypass.** `stripNonGistScripts` only matched `</script>`
  exactly, so `<script>…</script >`, `</script\t>`, and unterminated tags survived and were
  normalized to a live `<script>` by the filter. Rewrote it to match any script with
  whitespace/unterminated closers and to keep only a *terminated* https gist script; it now
  runs before **and** after `xssFilter`.
- **Major — OG path traversal.** `/og/..%2f…png` read arbitrary `.png` files. `serveOgPost`
  now rejects slugs containing `..`, `/`, `\`, or NUL with a plain-text 404.
- **Major — onboarding CSRF/takeover.** `POST /api/onboarding` accepted form-encoded bodies
  (cross-site "simple request"). Added `requireJsonBody()` → `415` on non-JSON, blocking the
  cross-origin form vector while keeping the JSON API (and the app's own fetch) working.
- **Major — SQLite WAL/SHM served.** Guard and `.htaccess` now match
  `.sqlite3?(-(wal|shm))?`.
- **Minor** — open redirect in `serveLoginGet`; `json_encode` of the editor token now uses
  `JSON_HEX_*`; removed unused `APP_NAME` and the unused test `$groupTitle`; dropped
  `express-rate-limit` comment references; `.gitignore` gains `*.sqlite3`; `bare.config.json`
  gains `"type": "php"` and a real `webroot` (`<deployTo>/public_html`); docs corrected
  (5-minute confirmation TTL, removed a false "dummy hash" claim, `public/og-images`,
  `views/layout.ejs`, `crypto.randomBytes`, health-check shape, Nginx deny list incl. `log`,
  `import.php` blocking scope).
- **Tests** — new `Security Hardening` and `Web Misc` groups (401 on protected API routes,
  CSRF 403 on login/logout, open-redirect reset, `415` onboarding, OG traversal, generic
  404s, `/docs`, `/onboarding` redirect, `custom_scripts`); script-bypass regression cases;
  `.env`/`*.sqlite.bak` probes now back up and restore; fixed weak assertions (`bodyHtml`,
  `<title>`, gist body, exact editor token). 464 PASS / 0 FAIL.

Deliberately not changed (documented): 4-space vs the references' 2-space indent (the
nowdoc CSS/JS/HTML would be corrupted by a blanket reindent); `clientIp()` proxy trust and
`X-Forwarded-Proto` (deployment-dependent; Writer runs behind Nginx); unused `array $m`
params on non-parameterised handlers.
