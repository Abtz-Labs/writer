# Writer → PHP port (Use Bare)

status: done

## Goal

Port Writer (Node/Express/EJS/JSLiteDB) to a single `index.php` following the Use Bare
principles shared by emmmail, tasssks and uptime. SQLite via PDO replaces JSLiteDB.
Final state: this repo contains the PHP app only; the Node stack is removed.

## Decisions (confirmed)

- Replace the Node code in this repo (build + verify first, then delete Node files).
- Search: SQLite FTS5, **trigram tokenizer**, bm25 weighted — replaces the JS fuzzy scorer.
- Full feature parity, adapted: GD for OG images, own pure-PHP Markdown parser,
  server-side Gist rendering, RSS, self-docs API, 2-step confirmations, Tiptap via CDN.
- One-time data importer for `storage/data/*.json` — **gitignored, never tracked**;
  deny it in `.htaccess`/bootstrap guard; conversion steps documented in docs/knowledge.
- Keep Writer's current layout/design; adopt the **Light Nord** palette (token map below).
- License: adopt **O'SAASy** (same LICENSE as the three references); README section updated.
- Legacy `$scrypt$` password hashes: **verify via ext-sodium**, rehash to bcrypt on login.
- **Recovery key** added (tasssks/uptime pattern): sha256-hashed, auto-rotate on use,
  accepted in the password field.
- Footer: keep "Powered by Writer", **add Use Bare link** (usebare.org,
  `utm_content=APP_VERSION`) — in-app, unit-tested like emmmail.
- Package.json stays as version mirror `{"version": "<APP_VERSION>"}` (tasssks/uptime).

## Use Bare principles (from tasssks/uptime README)

1. Single-file architecture — all application code in `index.php`.
2. No external PHP dependencies — no Composer, no autoloaders.
3. Write tests — `tests.php`, TDD when possible.
4. Keep it simple — straightforward code, match existing style.
5. Surgical changes — one thing per change, no collateral cleanups.

Tagline: "No frameworks. No Composer. No build tools. One file, one database, done."

## Target file layout

```
index.php          # the whole app (guard → config → db → security → helpers → API
                   #   → pages → views → CSS/JS → router)
tests.php          # HTTP + unit test suite (self-hosted server on :8091)
import.php         # one-time JSON→SQLite importer — GITIGNORED, denied by guard
LICENSE            # O'SAASy (copied from tasssks/uptime)
.htaccess          # Apache front controller + block .sqlite/.env/.git/.bak/tests.php/import.php
Justfile           # start / stop / test (php -S, lsof port check)
package.json       # {"version": "<APP_VERSION>"} mirror only
.gitignore         # + *.sqlite*, og-images/, import.php, .server.pid, .DS_Store
README.md          # rewrite: Use Bare style, 5 principles, requirements, health check
AGENTS.md          # update: PHP conventions, commands, knowledge table
bare.config.json   # full rewrite: ignore list, WRITER_DB_PATH, health-check start
writer.sqlite      # dev DB (gitignored)
og-images/         # OG PNG cache (gitignored)
assets/fonts/DejaVuSans.ttf   # bundled TTF for GD (Bitstream/Vera license, noted in README)
docs/              # keep; knowledge files regenerated in final phase
docs/supervisord.sample.conf  # sample Supervisor config (PM2 replacement, phase 11)
```

Removed in the final phase: `app.js`, `config/`, `models/`, `controllers/`,
`middleware/`, `routes/`, `services/`, `utils/`, `views/`, `public/`, `test/`,
`vitest.config.js`, `nodemon.json`, `package-lock.json`, `yarn.lock`, `.pnp.*`,
`.yarn/`, `node_modules/`, `coverage/`, `Dockerfile`, `docker-compose.yml`,
`.dockerignore`, `ecosystem.config.cjs`, `pm2-entry.cjs`, `.env.example`.
`storage/data` stays on disk as the importer source (gitignored).

## index.php section map (tasssks/uptime style)

Naming: **camelCase everywhere** (`renderPage()`, `viewFooter()`, `cssInline()`),
`// =====` banners, Allman braces — matches tasssks/uptime; no snake_case mix.

```
[cli-server guard]     FIRST (tasssks/uptime position): php -S asset passthrough +
                       sensitive-file block (404 for tests.php, import.php, *.sqlite,
                       *.env, .git, *.bak) — mirrors .htaccess
// ===== CONFIGURATION      define() + getenv('WRITER_*'), APP_VERSION, paths, port
// ===== DATABASE SETUP      getDb() PDO singleton (WAL, busy_timeout, foreign_keys),
//                           initDatabase(), migrateDatabase() — PRAGMA user_version
// ===== SECURITY            h(), csrfToken(), verifyCsrf(), passwordHash/Verify
//                           (bcrypt + legacy sha256 + $scrypt$ via sodium, rehash-on-login),
//                           recovery key, hash_equals token auth, login rate limit
// ===== HELPERS             jsonResponse(), getInput(), pagination, slug/uuid, redirect
// ===== METADATA            port of services/metadata.js + extractFirstImage()
// ===== MARKDOWN            pure-PHP GFM-subset parser (marked-compatible output)
// ===== SANITIZER           tokenizer port of the xss package (allowlist filter;
//                           script[src] only https://gist.github.com) — no ext-dom
// ===== RSS                 RSS 2.0 builder (hand XML, escaped)
// ===== GIST                gist fetch (api.github.com), 1h gist_cache, styled blocks;
//                           syntax highlight via hljs CDN client-side
// ===== OG IMAGES           GD 1200x630 card, auto-shrink title, file cache,
//                           invalidation on post AND settings changes
// ===== AUTH                session boot (params below), requireSession(),
//                           apiAuth(X-Auth-Token), optionalAuth()
// ===== API DOCS            GET /api self-describing docs payload (own section)
// ===== API ROUTER          route table: [METHOD, path-regex] → handler (REST kept)
// ===== API: POSTS          list/get/create/update/delete(202)+confirm
// ===== API: SETTINGS       get/update/credentials/rotate-token + onboarding + health
// ===== WEB ROUTER + PAGES  serveHome, serveSearch, servePost (draft preview banner),
//                           serveLogin/doLogin/doLogout, serveOnboarding, servePanel,
//                           serveSettings, serveDocs, serve404, serveFeed, serveOg
// ===== VIEWS               renderPage() + view*() string builders (h() escaped),
//                           credits footer (Writer + Use Bare link)
// ===== CSS + JS            cssInline() (Light Nord tokens, Writer layout),
//                           jsInline() (topics collapse, progress bar, editor glue)
// ===== BOOTSTRAP           initDatabase(), session start, route dispatch;
//                           if (defined('WRITER_TESTING')) return;
```

`WRITER_TESTING` is a **PHP constant**, `define()`d by `tests.php` before it requires
`index.php` as a library. The child HTTP server gets only `WRITER_DB_PATH` (absolute
path) in its environment — never `WRITER_TESTING`.

## Routing (REST paths kept — documented deviation)

`.htaccess` rewrites everything to `index.php` (Apache reference); production nginx uses
`try_files $uri /index.php` (documented in README). Dev: `php -S localhost:8080 index.php`.
The top guard passes real files through and blocks sensitive ones.

Same table as today: `/api/health`, `/api`, `/api/posts[...]`, `/api/settings[...]`,
`/api/onboarding`, `/api/confirm/:token`, `/`, `/search`, `/post/:slug`, `/login`,
`/logout`, `/docs`, `/onboarding`, `/panel`, `/posts` (redirect), `/settings`,
`/settings/update[-credentials]`, `/feed.xml`, `/og/{site,:slug}.png`, 404.

Deviations from reference style, recorded deliberately:
- **REST paths kept**: `GET /api` self-describing docs + `X-Auth-Token` curl usage is a
  documented public product surface (SPEC, README, routes.md). Path prefix `/api/*`
  replaces `?action=` as the CSRF-exempt boundary. Uptime already path-routes pages.
  Route table stays flat and single-sourced so dispatch and `/api` docs cannot drift.
- API is exempt from CSRF (`/api/*`); every other non-GET request runs `verifyCsrf()`.

## SQLite schema (user_version = 1)

```sql
settings(id TEXT PK, title, author, description, username, password_hash,
         recovery_key_hash, auth_token, about, custom_scripts, csp_script_domains,
         onboarding_complete INT, show_docs INT, created_at, updated_at)
posts(id TEXT PK, title, slug UNIQUE, body, excerpt, cover_image, keywords TEXT,
      meta_description, reading_time INT, status, tags TEXT /*json array*/,
      created_at, updated_at, published_at)
posts_fts USING fts5(title, slug, tags, keywords, excerpt, body,
                     content='posts', content_rowid='rowid',
                     tokenizer='trigram')
confirmations(token TEXT PK, action, data TEXT /*json*/, expires_at)
login_attempts(ip TEXT, attempted_at INT, success INT)
gist_cache(gist_id TEXT PK, payload TEXT, fetched_at INT)
```

FTS rules:
- Triggers use the external-content `'delete'` command first
  (`INSERT INTO posts_fts(posts_fts,...) VALUES('delete', old.rowid, ...)`), then insert.
  Naive triggers silently corrupt the index.
- No boot-time rebuild check — triggers own the index; importer runs one
  `INSERT INTO posts_fts(posts_fts) VALUES('rebuild')` at the end.
- `slug` is indexed (weight 6 in the old scorer). `keywords` is a comma-joined **string**
  in 100% of real rows — TEXT is correct, and unlike Node it now actually contributes
  to ranking (Node's `Array.isArray` guard meant keywords were never scored).
- Queries shorter than 3 chars: trigram cannot match them — fall back to a LIKE on
  title. Documented in code with one line.
- Sort everywhere: `ORDER BY COALESCE(NULLIF(published_at,''), created_at) DESC`
  (5 published posts have no `published_at`; Node defaulted it to `created_at`).

Migrations: idempotent `CREATE TABLE IF NOT EXISTS`, `PRAGMA user_version` stamps,
never edit a shipped case.

## Auth & security specifics (parity fixes from review)

- **Sessions**: `session_set_cookie_params` before `session_start()` — lifetime **86400**,
  httponly, samesite=Strict, secure on HTTPS; set `session.gc_maxlifetime=86400`.
  Store the **raw `auth_token`** in `$_SESSION['authToken']`; every auth check is
  `hash_equals($settings['auth_token'], $_SESSION['authToken'])` — so token rotation
  invalidates all sessions (parity with webAuth.js). Test required.
- **api\*() signatures take an explicit auth context** (the token). Web form handlers
  verify CSRF, then pass `$_SESSION['authToken']` to the same `api*()` functions —
  no HTTP self-fetch, and no dependence on `HTTP_X_AUTH_TOKEN` (form POSTs lack it).
  Integration test: `/settings/update` via web form succeeds.
- **Password verify**: bcrypt (new) + legacy sha256 (64-hex, timing-safe) +
  `$scrypt$salt$hash` via sodium (`sodium_crypto_pwhash_str_verify`-equivalent with
  Node params N=16384, r=8, p=1, keylen=64, digest `salsa20sha256`). Any success
  rehashes to bcrypt. Tests for all three formats + rehash-on-login.
- **Recovery key**: generated at onboarding, shown once, sha256-hashed in settings,
  auto-rotated on use, accepted in the login password field (tasssks/uptime pattern).
- **Rate limit**: login 5 attempts / 15 min per IP; onboarding 5/hour. **Successful
  logins do not count** (parity with `skipSuccessfulRequests`); client IP from
  `X-Real-IP`/`X-Forwarded-For` when present (trust proxy). Global 100/15min limiter
  dropped (session + CSRF cover it; noted in deviations).
- **CSP defaults** (parity app.js:81-99): `default-src 'self'; script-src 'self'
  'unsafe-inline' https://esm.sh https://cdn.jsdelivr.net; connect-src 'self'
  https://esm.sh; img-src 'self' data: https:; style-src 'self' 'unsafe-inline';`
  plus dynamic `csp_script_domains` from settings. Tests assert defaults and dynamic
  additions (ported from test/csp.test.js).
- **Sanitizer**: faithful tokenizer port of the `xss` package's filter (same lib the
  Node app used) with the default allowlist + `script: ['src']`, `href`/`src` prefix
  rules, `on*`/unknown attrs dropped, comments stripped; `script[src]` survives only
  for `https://gist.github.com` (mirrors models/post.js:27-50). Chosen over DOMDocument
  because libxml's entity round-trip cannot reproduce xss output byte-for-byte; the
  ported scanner is a real tokenizer (not a regex), so the XSS guarantee holds and
  `ext-dom` is not required.
- **API token compare** is `hash_equals` (fixes Node's timing-unsafe `!==`).
- **Settings leak**: `GET /api/settings` strips **both** `auth_token` and `username`
  (parity settingsController.js:22-23). Test asserts both.
- **Sensitive files**: `.htaccess` AND the cli-server guard both block
  `*.sqlite|*.env|.git|*.bak|tests.php|import.php`; HTTP test group added.
- **Panel token injection**: `json_encode($token, JSON_HEX_TAG|JSON_HEX_AMP|...)`.

## Feature port map (Node → PHP)

| Node | PHP |
| --- | --- |
| marked (GFM+breaks) | own pure-PHP parser in MARKDOWN section (~400 lines) |
| xss package | tokenizer port of the xss allowlist filter (see above) |
| JSLiteDB JSON files | PDO SQLite + WAL |
| fuzzy search (web.js:36-95) | FTS5 trigram + bm25 weights (title, slug, tags, keywords, excerpt, body) |
| @napi-rs/canvas OG images | GD `imagettftext` + bundled DejaVuSans.ttf |
| highlight.js (server) | server fetches + escapes gist; **hljs CDN (jsdelivr)** highlights client-side — accepted new origin in CSP; degraded offline (noted); gist test asserts structure only (`gist-embed`, filename), no `hljs-*` classes |
| cookie-session (24h) | native PHP sessions, 86400 lifetime |
| Helmet CSP | `header()` CSP with defaults above |
| express-rate-limit | `login_attempts` (login 5/15min, onboarding 5/hour, success skipped, X-Real-IP) |
| scrypt / sha256 hashes | bcrypt new + sha256 + `$scrypt$` verify, rehash-on-login |
| settings web handlers self-fetching API | handlers call `api*()` directly with explicit auth ctx |
| confirmation.js (5min TTL) | `confirmations` table, lazy expiry, 410 on expired |
| no recovery path | recovery key added (tasssks/uptime pattern) |
| Tiptap + Turndown via esm.sh | unchanged (CDN allowed — tasssks precedent) |
| EJS views/partials | `renderPage()` + `view*()` string builders |
| `public/css/style.css` | inline `cssInline()`, same class structure, Light Nord tokens |
| hljs themes `public/css/github*.css` | dropped; gist/code block styling lives in `cssInline()` |
| OG cache clear on post+settings change | same invalidation hooks (test via PNG mtime) |

Accepted deviations (recorded in README/plan):
FTS5 trigram ≠ fuzzy subsequence; global rate limit dropped; RSS regenerated per request
(no cache); `forceSavePost` bypass gone (single write path); REST paths kept (justified
above); keywords now scored (were dead in Node); hljs client-side vs server-side.

## Parity checklist (explicitly covered items)

Draft preview banner · reading progress bar · about-the-author boxes (home/search
sidebar + post footer) · cover image with **first-image fallback** (`extractFirstImage`) ·
prev/next post navigation · `/panel?slug=` deep-link · image-edit modal · search results
pagination · OG + Twitter meta tags in `<head>` · sticky notification banners ·
`show_docs` footer toggle · custom head scripts (unescaped) · topics expand/collapse
(mobile) · 404 page · `/api/health` returns **`{"status":"ok","timestamp":...}`** (current
shape, tested) · credits footer with Use Bare link.

## Nord mapping (Light — pinned, no redesign in phase 9)

| Current token | Nord value |
| --- | --- |
| `--color-bg` | `#eceff4` (nord6) |
| `--color-bg-secondary` | `#e5e9f0` (nord5) |
| `--color-text` | `#2e3440` (nord0) |
| `--color-text-secondary` / muted | `#4c566a` (nord3) |
| `--color-border` | `#d8dee9` (nord4) |
| `--color-accent` (was `#1a8917`) | `#a3be8c` (nord14) for fills; links use `#5e81ac` (nord9) for contrast |
| `--color-text-red` | `#bf616a` (nord11) |
| progress gradient (`#0fd9de`/`#895bfe`) | `#88c0d0` (nord8) → `#b48ead` (nord15) |
| OG card palette | nord0 background, nord6 text, nord14 accent (was near-black) |
| hardcoded GitHub-Primer hexes (tables/docs) | nearest nord tone, one-for-one |
| hljs theme | dropped; code blocks styled with nord tokens in `cssInline()` |

## Testing (`tests.php`)

- Harness: tasssks/uptime boot pattern (`proc_open` server on port **8091**, verified
  free, with absolute `WRITER_DB_PATH=test.sqlite`), curl + cookie jar + CSRF extraction;
  output format from AGENTS.md/emmmail: `### group ###` + PASS/FAIL/SKIP + summary block.
- Unit groups (require index.php as library behind `define('WRITER_TESTING')`):
  - markdown parser (fixtures incl. GFM tables, `breaks:true`, fenced bash, gist passthrough)
  - sanitizer (gist script preserved; inline/event-handler scripts stripped) — port of
    `test/post.test.js` (covers `toView`/`toApiJSON`, `isPublished`, `firstImage`)
  - metadata — 1:1 port of `test/metadata.test.js`
  - Post view/serialization
  - migrations idempotency + FTS trigger correctness (update/delete then re-query)
  - password verify: bcrypt / sha256 / `$scrypt$` / rehash-on-login
  - recovery key lifecycle
- HTTP groups:
  - health shape, api docs payload
  - **security headers** (nosniff, DENY, HSTS, CSP on `/`, `/api`, `/api/settings`)
  - CSP defaults + `csp_script_domains` add/clear (port of `test/csp.test.js`)
  - posts CRUD incl. **PUT preserves body when omitted**, non-string title/body → 400
    with exact messages, malformed JSON → 400 (port of `test/api.test.js`)
  - published-only listing, draft 404, draft preview with session
  - confirmation flow: delete 202 → confirm → gone; rotate; expired → **410**
  - settings leak (auth_token **and** username absent), onboarding single-use + rate limit
  - login rate limit (success not counted), session rotation invalidates
  - web form `/settings/update` (CSRF path into `api*()`)
  - topics contract (port of `test/topics.test.js`)
  - search: fixed-query ranking assertions on seeded data + short-query LIKE fallback
  - RSS shape, OG PNG magic bytes + cache mtime invalidation
  - gist render from **seeded `gist_cache`** (no network) — structure-only assertions
  - sensitive-file blocking (tests.php, import.php, *.sqlite → 404)
  - footer contains "Powered by Writer" + Use Bare link
- Isolation: own `test.sqlite` (+ unlink `-wal`/`-shm`) removed on exit — never touches
  real DB (fixes the documented vitest caveat).

## Markdown parity acceptance (transitional, deleted with Node in final phase)

`tools/render-node.mjs` imports the real `models/post.js` over `storage/data/posts.json`
and emits `{key, slug, bodyHtml: toView().bodyHtml, keywords, meta_description,
reading_time, excerpt}` (pre-gist HTML). `tools/parity.php` loads the same data through the
PHP functions and diffs field-by-field over **all 92 posts**:
- `bodyHtml`, `excerpt`, `firstImage`: **exact equality, 92/92** — no allowlist needed.
  (A whitespace/entity allowlist turned out unnecessary: the sanitizer is a faithful port
  of the `xss` package, so byte-for-byte parity is reachable.)
- `keywords`, `meta_description`, `reading_time`: phase 4.
Corpus census (verified): posts use only ATX headings, paragraphs, bold, inline/fenced
code (2), pipe tables (3), flat lists, blockquotes (3), strikethrough (1), images (14),
links, and gist `<script>` tags — no footnotes, task lists, nested lists, HRs.

## Importer (`import.php` — gitignored, denied by web)

CLI-only, dry-run first. Reads `storage/data/*.json`:
- `posts.json` is a **keyed object**: iterate values; `id = object.id ?? $key`
  (only 22/92 carry `id`).
- `published_at = published_at ?: created_at` (5 published posts lack it;
  76 drafts have it).
- `keywords` stays the comma string; `tags` JSON-encoded; normalize non-string
  `title`/`body` to string (one post has `title:{}`); empty slug kept as-is (1 post),
  flagged in dry-run output.
- settings singleton: keep stored hash (sha256 verify path handles it), keep the same
  `auth_token` so existing API clients survive; add `recovery_key_hash` NULL.
- `confirmations.json` skipped (all expired); `.index.json` skipped.
- Ends with FTS `'rebuild'`.
Dry-run acceptance counts: **92 posts (16 published / 76 draft), 1 settings**.
After import: run the parity harness, then spot-check in UI/API.
Live-server migration (Pi) is a **separate task** — see Not in scope.

## Phases (TDD: red → code → green)

1. **Harness + skeleton** — `tests.php` boots server; index.php guard + CONFIG/DB/
   SECURITY/ROUTER skeleton; red health test → green. Verify: `php -l`, `php tests.php`.
2. **Schema** — migrations v1 + FTS5 trigram + `'delete'`-command triggers; unit tests.
3. **Markdown + sanitizer** — DONE: 55 golden fixtures (end-to-end through the Node
   pipeline, frozen in `fixtures/markdown.jsonl`) + gist allowlist tests; `parseMarkdown`,
   `parseInline`, `sanitizeHtml` (tokenizer port of `xss`); parity **92/92 exact**.
4. **Metadata** — DONE: ported `test/metadata.test.js` 1:1 (28 cases); `generateSlug`,
   `generateUniqueSlug`, `extractKeywords`, `generateMetaDescription`, `calculateReadingTime`,
   `generateExcerpt`, `inferMetadata`; corpus parity **92/92 exact** on keywords/meta/reading/slug.
5. **API posts + auth + confirmations** — DONE: docs, auth (X-Auth-Token), posts CRUD
   (list/search-independent, slug inference, PUT preservation + validation messages),
   2-step confirmations (delete-post, rotate-token), onboarding + settings GET, security
   headers; scrypt `$scrypt$` hashing via libsodium (Node-interactive limits reproduce
   `scryptSync`). 200 PASS / 0 FAIL.
6. **Settings/onboarding/login/CSP/recovery** — DONE (auth core): password verify bcrypt +
   legacy sha256 + `$scrypt$` (sodium, Node-parity), `passwordNeedsRehash`, recovery key
   (generate/hash/verify, issued at onboarding), sliding-window rate limiter with bucket
   column + `X-Real-IP`/`X-Forwarded-For` (onboarding 5/hour wired), `PUT /api/settings`,
   `PUT /api/settings/credentials`, dynamic CSP from `csp_script_domains`. The
   login/session-dependent parts (session `authToken`, rotation invalidation,
   rehash-on-login, recovery-key login, `/settings/update` web form) need the login page
   and move to phase 7. 254 PASS / 0 FAIL.
7. **Web pages** — DONE: `WEB: RENDER` + `WEB: PAGES` sections (`formatPostDate`,
   `webSettings`, `renderPage`, `view*()` builders, `cssInline()`); home/tag/pagination,
   FTS5 search (`ftsMatchExpr` AND-of-terms + bm25 ranking, title-LIKE fallback for
   <3-char queries), post + prev/next + draft banner, 404 (renders the page),
   login/logout (+ session `authToken`, rehash-on-login, recovery-key login + rotation,
   rotation invalidation), onboarding (shows the one-time recovery key), docs,
   `/settings/update` web form (CSRF → `applySettingsUpdate`); `applySettingsUpdate`
   extracted from `apiUpdateSettings`; `notFound()` renders via `renderPage`.
   CLI-server guard uses `is_file()` (mirrors `.htaccess` `!-f`, so `/docs` is a route).
   Topics contract + home + search + post + login/session + rate-limit + settings-form +
   footer groups. 352 PASS / 0 FAIL; parity 92/92.
8. **RSS + OG + Gist** — DONE: `RSS` (`escapeXml`, `httpDate`, `serveFeed` → `/feed.xml`,
   Cache-Control 3600), `GIST` (`fetchGistJson` 1h `gist_cache` + curl 10s timeout,
   `renderGistsInHtml` replaces gist `<script src>` with styled embeds; code escaped and
   highlighted client-side by hljs from jsdelivr, wired in `renderPage`), `OG IMAGES`
   (GD 1200x630 card, auto-shrink title, bundled DejaVuSans + DejaVuSans-Bold, file cache
   `og-images/`, `/og/site.png` + `/og/:slug.png`, plain "Not found" 404). Invalidation:
   post update (old+new slug) + delete, settings update, onboarding. `rowToPost` now applies
   Node's `created_at || now` / `published_at || created_at` fallbacks (feeds RSS + sorting).
   401 PASS / 0 FAIL; parity 92/92.
9. **Panel + settings UI** — DONE: `viewPanelNav`/`viewPanel`/`viewPanelScript` +
   `viewSettings`/`viewSettingsScript` in `WEB: RENDER`; `servePanel`, `serveSettings`,
   `serveSettingsUpdateCredentials` in `WEB: PAGES`; routes `GET /panel`, `GET /posts`
   (→ `/panel`), `GET /settings`, `POST /settings/update-credentials`. `applyCredentialsUpdate`
   extracted from `apiUpdateCredentials` (mirrors `applySettingsUpdate`). Panel search uses
   FTS5 over **all** posts (drafts included) via `searchAllPosts`; `viewPagination` gained an
   optional `id` (panel needs `id="pagination"`). Fixed a latent `show_docs` bug: the HTML form
   fallback sends `"on"`, which `(int)` cast to `0` — `applySettingsUpdate` now coerces it.
   Minor deviation: the settings page renders the credentials-mismatch error (Node threaded
   `error` into the view but never displayed it). 464 PASS / 0 FAIL. Visual browser check not
   possible in this environment (terminal-browser pane detection unavailable).
10. **Importer** — DONE: `import.php` (gitignored, CLI-only, 404 over HTTP). Dry-run counts →
    insert (`id` falls back to the JSON object key for the 70 keyless posts) → settings
    (unwraps the `{settings:{…}}` envelope) → confirmations → one FTS `'rebuild'`. Verified:
    92 posts (16 published), FTS searchable, feed 16 items, OG/home/post/search 200, imported
    `auth_token` works. Guarded test group `Importer` runs it against a temp DB (skips when the
    transitional files are absent). Also fixed `Sensitive Files` to restore a pre-existing
    `import.php` instead of deleting it.
11. **Remove Node + docs** — delete Node artifacts + parity tools; add LICENSE (O'SAASy);
    rewrite README (5 principles, requirements `ext-pdo_sqlite` (FTS5), `ext-gd`+FreeType,
    health-check section, license, footer credit); update AGENTS.md;
    update docs/SPEC.md (stack, hashing AC, Docker AC, search) or mark superseded;
    regenerate docs/knowledge/{routes,commands,settings,testing,architecture}.md,
    delete docs/knowledge/docker.md; rewrite bare.config.json; add
    `docs/supervisord.sample.conf` (Supervisor wraps `php -S`/php-fpm when a process
    manager is needed — README "Process manager" section recommends it as the PM2
    replacement; dev workflow stays `just start`); regenerate
    docs/knowledge/deployment.md (php-fpm + Supervisor, retire PM2/ecosystem notes);
    add dated fragment to `docs/memory/<yyyy-mm-dd>-writer-php-port.md`.

## Not in scope

- Deployment to raspberrypi.local (cutover runbook is a separate task: backup JSON →
  copy importer → import on server → per-app php-fpm pool with `WRITER_DB_PATH` +
  session path + `og-images/` → verify `X-Auth-Token` → retire PM2 apps).
  Phase 11 only rewrites `bare.config.json` for the PHP world.
- Docker/PM2 support (removed, SPEC AC amended in phase 11).
- Multi-user/multi-tenancy, comments, newsletter, sitemap.xml.
- DB-corruption auto-backup (SPEC edge case never implemented in Node — not inherited).
- `storage/data` cleanup after verified import. The store was removed from this checkout;
  `import.php` reads it from a live deployment when migrating.
- Global request rate limiter (deviation above).
- Publishing/repo transfer mechanics, usebare.org content.

## Verification (final)

- `php -l index.php && php -l tests.php` clean.
- `php tests.php` all green (counts recorded in the memory fragment).
- Fresh DB: onboarding → create draft → publish → home/search/post/RSS/OG/recovery work.
- Imported DB: 92 posts, settings intact, old slugs resolve, `X-Auth-Token` works,
  parity harness green.
- Grep: no Composer files; no `require`/`include` outside index/tests; no `WRITER_TESTING`
  env in server boot; `import.php` absent from git status.
- README carries the 5 principles, requirements, health check, O'SAASy license section.

## Review record

Plan reviewed 2026-10-14 by four independent reviewers (Use Bare conventions, technical
correctness, testing/migration, scope/parity). All blocker findings folded in above;
decisions confirmed with the owner.
