# Checkpoints

Persistent memory for cross-session continuity. Updated as work progresses.

## Current Status

- **published_at field**: Implemented and working. Posts sort by `published_at` desc everywhere.
- **Tests**: 42 passing, 99.07% line coverage.
- **Panel UI**: Published column + datetime-local input for editing published_at.
- **Tiptap editor**: Rich text editor in panel via CDN (esm.sh). Markdown stored in DB, HTML in editor.

## Key Decisions

- `published_at` defaults to `created_at` if not provided (Post constructor: `this.published_at = data.published_at || this.created_at`)
- All sorting uses `published_at` desc (home, search, panel, RSS feed, API)
- Helper function `getPublishDate(post)` in `routes/web.js` returns `published_at || created_at` for robust fallback
- CSS: user prefers CSS classes over inline styles. Bulma `.field.is-grouped` with nested `.field` children causes stacking issues — avoid this pattern.
- **Tiptap CDN approach**: Uses `esm.sh` for `@tiptap/core`, `@tiptap/starter-kit`, `@tiptap/extension-link`, `@tiptap/extension-image`, `@tiptap/extension-placeholder`, and `turndown`. No build step.
- **Markdown ↔ HTML conversion**: Posts stored as Markdown. Load: `marked.parse()` converts to HTML for Tiptap. Save: `turndown.turndown()` converts HTML back to Markdown.

## Architecture Notes

- Post model (`models/post.js`): `toJSON()`, `toApiJSON()`, `toView()` all include `published_at`
- Validation: `validatePostInput()` in `controllers/postController.js` checks ISO 8601 format for `published_at`
- API: `POST /api/posts` and `PUT /api/posts/:slug` accept optional `published_at`
- Panel form (`views/pages/panel.ejs`): JS handles datetime-local input, sends ISO string to API
- CSP (`app.js`): `esm.sh` added to `scriptSrc` and `connectSrc` for Tiptap CDN imports
- Tiptap toolbar uses `data-tiptap-command` and `data-tiptap-attrs` attributes for command dispatch

## Pending / Blockers

- Side-by-side layout for Status + Published at fields in panel form: Bulma `.field.is-grouped` didn't work. Still using inline `flex: 1` styles. User wants CSS class approach.

## Conventions

- No inline CSS in templates — use style files (`public/css/style.css`)
- Bulma for form layout
- Knowledge base files in `docs/knowledge/`
- `AGENTS.md` is the entry point; always update `checkpoints.md` when making significant changes
