# Checkpoints

Current project status. Updated as work progresses.

## Status

- **Writer is a single-file PHP app.** The Node/Express/EJS/JSLiteDB stack has been
  removed. SQLite (PDO, FTS5 trigram) replaces JSLiteDB; GD replaces `@napi-rs/canvas`;
  the Markdown parser and the HTML sanitizer are pure PHP; the admin editor (Tiptap) and
  syntax highlighting (highlight.js) load from a CDN.
- **Tests:** `php tests.php` — all green (see [Testing](testing.md)).
- **Data:** `writer.sqlite` is the database. `import.php` migrates an old JSON store that
  you copy into `storage/data/` when coming from the Node version.
  migrates it to SQLite; it is a one-time, CLI-only script.

## Deliberate deviations from the Node version

- **Search** is SQLite FTS5 with bm25 ranking, not the JS fuzzy scorer; results are ranked
  by relevance rather than re-sorted by date.
- **Onboarding** shows the one-time recovery key (the Node version did not).
- **The settings page** renders the credentials-mismatch error (the Node view threaded an
  `error` value but never displayed it).

## Known Markdown edge cases

The parser targets `marked` parity (not strict CommonMark). Remaining differences on inputs
the corpus did not exercise: `***bold italic***` and strong-inside-emphasis such as
`*a **b** c*`, and single-tilde `~text~` (treated as strikethrough). None appear in the
sample content.

## Conventions

- Single-file architecture is intentional. All code stays in `index.php`.
- No Composer, no autoloaders, no build step.
- Section banners use `// =====`; naming is camelCase.
- Knowledge files live in `docs/knowledge/`; session fragments in `docs/memory/`.
