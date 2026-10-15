# Architecture

## Request Flow

`index.php` runs top to bottom:

1. **CLI-server guard** — when served by `php -S`, real files pass through and sensitive
   ones (`tests.php`, `import.php`, `*.sqlite`, `*.env`, `.git`, `*.bak`) return `404`.
   Mirrors the intent of `.htaccess`.
2. **Configuration** — `define()` constants, `WRITER_DB_PATH`, section banners.
3. **Bootstrap** — `bootSession()`, `initDatabase()`, security headers, `dispatch()`.
   Under `WRITER_TESTING`, bootstrap returns early and the file acts as a library.

`dispatch()` is a flat route table: `route($method, $pattern, $handler)` matches an
anchored PCRE against `requestPath()` and calls the handler. API handlers return JSON;
page handlers call `renderPage()`.

## Authentication

- **First-time setup** — `POST /api/onboarding` (or the `/onboarding` form) creates the
  settings row with a random `auth_token`, a hashed password, and a hashed recovery key.
- **API auth** — protected endpoints require the `X-Auth-Token` header, compared with
  `hash_equals`. `requireAuthRow($token)` is the single check; API route wrappers call
  `requireAuth()` (header), and web handlers pass the session token explicitly.
- **Web auth** — the session cookie stores the same token in `$_SESSION['authToken']`.
  Login accepts username/password, the auth token, or the recovery key.
- **Before setup** — while `onboarding_complete` is false, `dispatch()` redirects every
  human-facing GET to `/onboarding`; API, feed and OG routes are exempt.
- **Rate limiting** — a sliding-window limiter keyed by bucket + IP (`login` 5/15min,
  `onboarding` 5/hour). Successful logins are never counted.

## Security Model

| Concern             | Implementation                                                                                  |
| ------------------- | ----------------------------------------------------------------------------------------------- |
| Password hashing    | bcrypt (`password_hash`). Legacy sha256 and `$scrypt$` (sodium) hashes verify and rehash on login. |
| Password comparison | `password_verify`; the auth token and recovery key use `hash_equals`.                             |
| Auth tokens         | `bin2hex(random_bytes(32))`.                                                                     |
| CSRF                | Per-session token in every web form's `_csrf` field, checked by `verifyCsrf()` on web POSTs.     |
| Sessions            | `HttpOnly`, `SameSite=Strict`, `Secure` when HTTPS, 24h lifetime, regenerated on login.          |
| CSP                 | Built from defaults plus `csp_script_domains`; `script-src` allows `'self'`, `'unsafe-inline'`, `esm.sh`, `cdn.jsdelivr.net`. |
| Draft exposure      | Public API and pages only return published posts; drafts 404 unless authenticated (preview).      |
| Token leak          | `GET /api/settings` strips `auth_token` and `username`.                                           |
| Recovery key        | sha256-hashed at rest, rotates on use.                                                            |

## Post Creation Pipeline

On create/update (`inferMetadata()`):

- **slug** — lowercase, alphanumerics + hyphens; collisions get `-1`, `-2` suffixes.
- **keywords** — top 10 words (3+ chars, stop words removed) by frequency.
- **meta_description** — first 160 chars of plain text.
- **reading_time** — `ceil(wordCount / 200)`, minimum 1.
- **excerpt** — first 200 chars of plain text (Markdown stripped).

## Post Timestamps

- **created_at** — set on creation; never modified.
- **updated_at** — set on create and update.
- **published_at** — defaults to `created_at`; settable for scheduled dates. All ordering
  uses `published_at` descending.

## Destructive Action Confirmation

`DELETE /api/posts/:slug` and `POST /api/settings/rotate-token` return `202` with a
`confirmation_url`. An authenticated `POST` to that URL executes the action. Tokens live
in the `confirmations` table with a 5-minute TTL and lazy cleanup; expired/invalid tokens
return `410`.

## Data Layer

SQLite via PDO, in `getDb()` (WAL, `busy_timeout`, `foreign_keys`). Three tables:

- **posts** — the blog posts.
- **settings** — a single row (`id = 'settings'`).
- **confirmations** — pending destructive actions.
- **posts_fts** — FTS5 virtual table (`tokenize='trigram'`) kept in sync by
  `posts_ai` / `posts_ad` / `posts_au` triggers using the `'delete'` command. The importer
  runs one `'rebuild'` after a bulk load; there is no boot-time rebuild.

Migrations use `PRAGMA user_version` (currently v2).

## Rendering

- Bodies are stored as Markdown; `renderBodyHtml()` = `sanitizeHtml(parseMarkdown($body))`.
- The sanitizer is a faithful tokenizer port of the `xss` package (no `ext-dom`); it keeps
  `<script src>` only for `gist.github.com` and strips everything else.
- `renderPage()` builds the full HTML document: meta, Open Graph tags, inlined CSS, the
  header, the page body, the footer, modals, and the highlight.js boot script.
- Pages are built by `view*()` string builders (`viewIndex`, `viewPost`, `viewPanel`,
  `viewSettings`, …). Output is escaped with `h()`.
- Syntax highlighting is client-side: highlight.js loads from jsdelivr and runs
  `hljs.highlightAll()`.

## Search

`searchPublishedPosts($q)` builds an FTS5 `MATCH` from quoted per-word terms (terms shorter
than 3 chars are dropped, since a trigram index cannot match them) and orders by
`bm25(posts_fts, 10, 6, 8, 7, 4, 1)` over title, slug, tags, keywords, excerpt and body.
Queries under 3 chars fall back to a title `LIKE`. The admin panel uses the same expression
over all posts (drafts included).

## Responsive Topic List

The topic filter on `/` and `/search` is one shared builder (`viewTopics()`). On mobile the
list is clipped to a single line with a `…` control; tapping expands it and the control
becomes "Show less". From 769px up the list wraps and the control is hidden. The control's
`hidden` attribute is set by an inline script when the list does not overflow.

## OG Images and RSS

- `/og/site.png` and `/og/:slug.png` render 1200×630 PNGs with GD (`generateOGImage()`),
  cached in `og-images/` (gitignored) and served with `Cache-Control: max-age=86400`.
  The cache is cleared on post update/delete and on settings/onboarding changes.
- `/feed.xml` builds RSS 2.0 by hand (up to 20 published posts) with
  `Cache-Control: max-age=3600`.

## Gists

`renderGistsInHtml()` replaces each gist `<script src>` in a rendered body with a styled
embed. Gist JSON is fetched once and cached in `gist_cache` for an hour
(`GIST_CACHE_TTL`). Code is escaped and given an `hljs language-*` hook.
