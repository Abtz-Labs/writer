# Checkpoints

Persistent memory for cross-session continuity. Updated as work progresses.

## Current Status

- **Mobile topics collapse**: On mobile (< 769px) the Topics list is a single
  clipped line with a `…` control pinned right. Clicking expands and the control
  becomes `Show less` at the end of the list. Desktop is unchanged. Shared
  partial `views/partials/topics.ejs` (markup + script), used by both `index.ejs`
  and `search.ejs`.
- **published_at field**: Implemented and working. Posts sort by `published_at` desc everywhere.
- **Tests**: 60 passing.
- **uuid dependency removed**: Replaced `uuid` package with Node.js built-in `crypto.randomUUID()` to fix `ReferenceError: crypto is not defined` on Node.js 18 in ESM mode.
- **Panel UI**: Published column + datetime-local input for editing published_at.
- **Tiptap editor**: Rich text editor in panel via CDN (esm.sh). No toolbar — uses Markdown input rules (e.g. `**bold**`, `# heading`). "Supports Markdown syntax." help text below editor.
- **Table support**: Tiptap now includes Table extensions and `turndown-plugin-gfm` handles HTML → Markdown table round-trip. CSS added for table rendering in the editor.

## Key Decisions

- **Topics control is a shared partial**: `views/partials/topics.ejs` holds the
  markup and its own inline script, following the `partials/modals.ejs` pattern.
  Include path from a page is `include("../partials/topics")`.
- **`activeTag` sentinel contract**: `routes/web.js` passes `activeTag: tag || ""`
  on `/` and passes nothing on `/search`. The partial checks
  `typeof activeTag !== "undefined"` to decide whether to mark anything active.
  With `null` this is impossible to distinguish, and `/search` would start
  highlighting `All`. Do not change `""` back to `null`.
- **Fade geometry**: `.tag-list::after` is `calc(var(--topics-toggle-width) * 2)`
  wide and reaches full `--color-bg` at `var(--topics-toggle-width)`, so the
  whole ramp sits to the left of the control and chips are fully faded before
  they reach it. A fade the same width as the control leaves chips visibly cut.
- **Control styled apart from chips**: `border-color: var(--color-text-light)`
  and `color: var(--color-text)`, against the chips' `var(--color-border)` and
  `var(--color-text-light)`. User asked for a solid border here, not dashed.
- **Control is hidden when it has nothing to reveal**: the partial's script sets
  `button.hidden` when `scrollWidth <= clientWidth` and the list is collapsed, so
  a blog with one or two topics shows no control. Needs
  `.tag-list-toggle[hidden] { display: none }` because author rules beat the
  user-agent `[hidden]` rule.
- **Tag click handlers are scoped to `a.tag`**, not `.tag`, so they do not match
  the `<button class="tag tag-list-toggle">`.
- `published_at` defaults to `created_at` if not provided (Post constructor: `this.published_at = data.published_at || this.created_at`)
- All sorting uses `published_at` desc (home, search, panel, RSS feed, API)
- Helper function `getPublishDate(post)` in `routes/web.js` returns `published_at || created_at` for robust fallback
- CSS: user prefers CSS classes over inline styles. Bulma `.field.is-grouped` with nested `.field` children causes stacking issues — avoid this pattern.
- **Tiptap CDN approach**: Uses `esm.sh` for `@tiptap/core`, `@tiptap/starter-kit`, `@tiptap/extension-link`, `@tiptap/extension-image`, `@tiptap/extension-placeholder`, `@tiptap/extension-table`, `@tiptap/extension-table-row`, `@tiptap/extension-table-cell`, `@tiptap/extension-table-header`, `turndown@7.2.4`, and `turndown-plugin-gfm@1.0.2`. No build step.
- **Markdown ↔ HTML conversion**: Posts stored as Markdown. Load: `marked.parse()` converts to HTML for Tiptap. Save: `turndown.turndown()` converts HTML back to Markdown.
- **List item `<p>` tags**: Tiptap wraps `<li>` content in `<p>`. Handled via CSS (`margin: 0` on `.content li p`) rather than HTML mutation.
- **ES module scope**: `<script type="module">` in panel.ejs requires `window.` prefix for global functions (`showNotification`, `showConfirmModal`, `showCreateForm`, `editPost`, `deletePost`).

## Architecture Notes

- Post model (`models/post.js`): `toJSON()`, `toApiJSON()`, `toView()` all include `published_at`
- Validation: `validatePostInput()` in `controllers/postController.js` checks ISO 8601 format for `published_at`
- API: `POST /api/posts` and `PUT /api/posts/:slug` accept optional `published_at`
- Panel form (`views/pages/panel.ejs`): JS handles datetime-local input, sends ISO string to API. Tiptap loads `bodyHtml` (HTML from `marked.parse`) and saves via `turndown.turndown()` back to Markdown. Table extensions + `turndown-plugin-gfm` ensure tables survive the round-trip.
- CSP (`app.js`): `esm.sh` added to `scriptSrc` and `connectSrc` for Tiptap CDN imports

## Pending / Blockers

- Side-by-side layout for Status + Published at fields in panel form: Bulma `.field.is-grouped` didn't work. Still using inline `flex: 1` styles. User wants CSS class approach.

## Conventions

- No inline CSS in templates — use style files (`public/css/style.css`)
- Bulma for form layout
- Knowledge base files in `docs/knowledge/`
- `AGENTS.md` is the entry point; always update `checkpoints.md` when making significant changes
