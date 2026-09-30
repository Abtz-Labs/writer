# Testing

- `test/metadata.test.js` — unit tests for slug generation, keyword extraction, reading time, etc.
- `test/post.test.js` — Post model serialization (`toView` / `toApiJSON`).
- `test/gistRenderer.test.js` — Gist embedding renderer.
- `test/csp.test.js` — Content-Security-Policy headers and custom script domains.
- `test/api.test.js` — integration tests using Supertest against the Express app.
- `test/topics.test.js` — rendered HTML contract for the shared topics partial
  (expand control present on `/` and `/search`, both labels emitted, tag anchors
  rendered, and the active-tag state for each page).
- Vitest config: `vitest.config.js` — node environment, 10s timeout, globals on.
  Run with `yarn vitest run` (Yarn PnP; there is no `node_modules`).

## Notes

- Environment is `node`, not jsdom, so tests assert rendered HTML rather than
  layout. CSS behaviour (the mobile topic collapse) is verified in a browser, not
  in the suite.
- The suite runs against the real `storage/data` unless `STORAGE_PATH` is set.
  `api.test.js` creates posts, deletes them, and rotates `auth_token`. Back up
  `storage/data` before a run and restore it after, or a local auth token is
  invalidated.
- `POST /api/posts` defaults to `status: "draft"`. Tests that need the home page
  tag list must pass `status: "published"`.
