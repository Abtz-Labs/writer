# Architecture

## Request Flow

Two route groups mounted in `app.js`:

- **Web routes** (`routes/web.js` → `GET /`): Serve HTML via EJS templates. A custom `res.render()` override in `app.js` wraps every page template in `views/layout.ejs` automatically. All web POST routes are protected by CSRF validation.
- **API routes** (`routes/api.js` → `GET /api`): Return JSON. `GET /api` serves self-discoverable API docs. API routes are exempt from CSRF checks.

## Authentication

- **First-time setup**: `POST /api/onboarding` or `/onboarding` web form — creates settings with a cryptographically random `auth_token` (256-bit hex).
- **API auth**: Protected endpoints require `X-Auth-Token` header, validated by `middleware/auth.js` against the stored token.
- **Web auth**: `cookie-session` stores `req.session.authToken` after login via `/login`. Session key from `SESSION_KEY` env var. Sessions use `httpOnly`, `sameSite=strict`, and `secure` in production.
- **Two login methods**: auth token directly, or username/password (scrypt-hashed password in settings).
- **Rate limiting**: Global 100 req/15min (skipped for authenticated users). Login is limited to 5 attempts per 15 min. Onboarding is limited to 5 per hour.

## Security Model

| Concern             | Implementation                                                                                                                                                      |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Password hashing    | `crypto.scryptSync()` with random 16-byte salt per password. Format: `$scrypt$<salt>$<hash>`. Legacy SHA256 hashes are still verifiable for backward compatibility. |
| Password comparison | `crypto.timingSafeEqual()` on equal-length buffers to prevent timing attacks.                                                                                       |
| Auth tokens         | `crypto.randomBytes(32).toString('hex')` — never predictable.                                                                                                       |
| CSRF                | Per-session token injected into all EJS templates as `csrfToken`. Validated on every non-API POST.                                                                  |
| Session cookies     | `cookie-session` with `httpOnly: true`, `sameSite: 'strict'`, `secure: process.env.NODE_ENV === 'production'`.                                                      |
| CSP                 | `defaultSrc 'self'`, `styleSrc 'self' 'unsafe-inline'`, `imgSrc 'self' data: https:`, `scriptSrc 'self' 'unsafe-inline'`, `scriptSrcAttr 'none'`.                   |
| Rate limiting       | Global 100 req/15min (skipped for authenticated users) + login-specific 5 req/15min + onboarding 5 req/hour.                                                        |
| Draft exposure      | Public API only returns `status === 'published'`. Draft slugs return 404 unless request carries a valid `X-Auth-Token`.                                             |
| Token leak          | `GET /api/settings` strips `auth_token` from the response.                                                                                                          |

## Post Creation Pipeline

When a post is created (`POST /api/posts`) or updated:

1. `metadata.inferMetadata()` — generates from title + body:
   - **slug**: lowercase, alphanumeric + hyphens; conflicts resolved with `-1`, `-2` suffix via `generateUniqueSlugFromList()`
   - **keywords**: top 10 words (3+ chars, excluding stop words) by frequency
   - **meta_description**: first 160 chars of plain text (Markdown stripped)
   - **reading_time**: `ceil(wordCount / 200)`, minimum 1
   - **excerpt**: first 200 chars of plain text
2. Post inserted into JSLiteDB `posts` collection.

## Post Timestamps

Each post has three timestamp fields:

- **created_at**: Set automatically when the post is created. Cannot be modified.
- **updated_at**: Set automatically when the post is created or updated.
- **published_at**: Controls when the post appears published. Defaults to `created_at` if not provided. Can be set via API or UI to schedule future publication dates. Posts are sorted by `published_at` descending.

## Destructive Action Confirmation Flow

Deleting a post or rotating the auth token requires a two-step confirmation:

1. `DELETE /api/posts/:slug` or `POST /api/settings/rotate-token` returns `202 Accepted` with `{ confirmation_required: true, confirmation_url: "/api/confirm/<token>" }`.
2. `POST /api/confirm/:token` with valid `X-Auth-Token` executes the pending action and deletes the confirmation token.
3. Invalid or expired confirmation tokens return `410 Gone`.

Confirmation tokens are persisted in JSLiteDB (`confirmations` collection) with a 10-minute TTL and lazy cleanup.

## Data Layer

- **JSLiteDB** singleton in `config/database.js` — stores JSON files in `storage/data/`. Access collections via `getCollection(name)`.
- **Three collections**: `posts`, `settings` (single document with `id: 'settings'`), and `confirmations`.
- Collection methods (`find`, `insert`, `update`, `delete`) are async. `find()` with no args returns all documents; `find({ key: value })` filters.
- No schema validation — collections auto-created on first insert.

## Rendering

- Post body stored as Markdown, rendered to HTML via `marked` (GFM + breaks enabled).
- Post model has three serialization methods: `toJSON()` (raw), `toApiJSON()` (adds `bodyHtml`), `toView()` (adds `bodyHtml` + `isPublished` + `firstImage`).
- Layout: `views/layout.ejs` wraps all pages. Pages in `views/pages/`, partials in `views/partials/`.

## Responsive Topic List

The topic filter on `/` and `/search` is one shared partial,
`views/partials/topics.ejs`, holding both the markup and its inline script. On
mobile the list is capped to a single line so a long tag set cannot push the post
list off screen; tapping a control expands it and the control becomes "Show less".

- **Collapsed (mobile base)**: `.tag-list` is `flex-wrap: nowrap` with
  `overflow: hidden`. A trailing `…` control sits absolutely at the right edge.
- **Fade**: `.tag-list::after` is a gradient to `--color-bg`, twice the control's
  width, reaching full opacity at the control's own width. The ramp therefore sits
  entirely to the left of the control and chips are faded out before reaching it.
- **Expanded**: `.is-expanded` on the list restores `flex-wrap: wrap`, drops the
  fade, and returns the control to normal flow so "Show less" sizes to its label.
- **Desktop (>= 769px)**: the control and the fade are both `display: none` and
  the list is fully wrapped. No JS is involved, so the layout is viewport-driven.
- **Overflow check**: the partial's script sets the control's `hidden` attribute
  when the list does not overflow, so a blog with one or two topics shows no
  control at all. Re-checked on `resize`.
- **Accessibility**: the control is a `<button>` with `aria-expanded` and
  `aria-controls`. The visible `…` is `aria-hidden`, and a visually hidden
  "Show all topics" label supplies the accessible name, so the two states read as
  "Show all topics" and "Show less".
- Tag click handlers are scoped to `a.tag`, not `.tag`, so they do not match the
  control despite it carrying the `.tag` class.

- OG images: dynamically generated 1200×630 PNGs via `@napi-rs/canvas`. Cached in `public/og-images/`. Route `/og/:slug.png` serves post cards; `/og/site.png` serves the site-wide card.
- RSS feed: `/feed.xml` returns valid RSS 2.0 with up to 20 published posts.
