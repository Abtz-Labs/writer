# CLAUDE.md

This file provides guidance to AI Agents when working with code in this repository.

## Project Overview

Serif Blog — a single-tenant, Medium-style blog web application with a RESTful API, Markdown support, and automatic content metadata inference. Built with Express.js, EJS templating, JSLiteDB (file-based JSON document store), and Bulma CSS.

## Commands

```bash
npm start              # Dev server with nodemon hot-reload (default port 8080)
node app.js            # Production start
node app.js -p 4000    # Custom port
npm test               # Jest tests with coverage
npm run test:watch     # Jest watch mode
npx jest test/metadata.test.js              # Single test file
npx jest --testNamePattern="slug generation" # Specific test by name
docker-compose up      # Run in Docker
```

## Architecture

### Request Flow

Two route groups mounted in `app.js`:
- **Web routes** (`routes/web.js` → `GET /`): Serve HTML via EJS templates. A custom `res.render()` override in `app.js` wraps every page template in `views/layout.ejs` automatically.
- **API routes** (`routes/api.js` → `GET /api`): Return JSON. `GET /api` serves self-discoverable API docs.

### Authentication

- **First-time setup**: `POST /api/onboarding` or `/onboarding` web form — creates settings with `auth_token = MD5(title + timestamp)`.
- **API auth**: Protected endpoints require `X-Auth-Token` header, validated by `middleware/auth.js` against the stored token.
- **Web auth**: `cookie-session` stores `req.session.authToken` after login via `/settings/login` or `/posts/login`. Session key from `SESSION_KEY` env var.
- Two login methods: auth token directly, or username/password (SHA256-hashed password in settings).

### Post Creation Pipeline

When a post is created (`POST /api/posts`) or updated:
1. `imageProcessor.processImages()` — regex-finds image URLs in body, downloads to `public/uploads/` with UUID filenames, replaces URLs with local `/uploads/` paths. 10s timeout per image; failures keep original URL.
2. `metadata.inferMetadata()` — generates from title + processed body:
   - **slug**: lowercase, alphanumeric + hyphens; conflicts resolved with `-1`, `-2` suffix via `generateUniqueSlugFromList()`
   - **keywords**: top 10 words (3+ chars, excluding stop words) by frequency
   - **meta_description**: first 160 chars of plain text (Markdown stripped)
   - **reading_time**: `ceil(wordCount / 200)`, minimum 1
   - **excerpt**: first 200 chars of plain text
3. Post inserted into JSLiteDB `posts` collection.

### Data Layer

- **JSLiteDB** singleton in `config/database.js` — stores JSON files in `storage/data/`. Access collections via `getCollection(name)`.
- **Two collections**: `posts` and `settings` (settings is a single document with `id: 'settings'`).
- Collection methods (`find`, `insert`, `update`, `delete`) are async. `find()` with no args returns all documents; `find({ key: value })` filters.
- No schema validation — collections auto-created on first insert.

### Rendering

- Post body stored as Markdown, rendered to HTML via `marked` (GFM + breaks enabled).
- Post model has three serialization methods: `toJSON()` (raw), `toApiJSON()` (adds `bodyHtml`), `toView()` (adds `bodyHtml` + `isPublished` flag).
- Layout: `views/layout.ejs` wraps all pages. Pages in `views/pages/`, partials in `views/partials/`.

### Web Pages

| Route | Page | Auth Required |
|-------|------|---------------|
| `GET /` | Post list (published only, newest first, tag filtering) | No |
| `GET /post/:slug` | Post detail with prev/next navigation | No |
| `GET /onboarding` | First-time blog setup form | No |
| `GET /settings` | Blog settings management | Session |
| `GET /posts` | Admin post list (all statuses) | Session |
| `GET /docs` | Documentation page | No |

### API Endpoints

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/api` | No — returns API docs |
| `GET` | `/api/posts` | No — paginated (`?page=&limit=`) |
| `GET` | `/api/posts/:slug` | No |
| `POST` | `/api/posts` | Yes — `{ title, body, tags[], cover_image?, status? }` |
| `PUT` | `/api/posts/:slug` | Yes |
| `DELETE` | `/api/posts/:slug` | Yes |
| `GET` | `/api/settings` | No |
| `POST` | `/api/onboarding` | No — `{ title, author?, description? }` |
| `PUT` | `/api/settings` | Yes |
| `POST` | `/api/settings/rotate-token` | Yes |
| `PUT` | `/api/settings/credentials` | Yes — `{ username, password }` |

## Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `PORT` | `8080` | HTTP server port (also settable via `-p` CLI flag) |
| `SESSION_KEY` | `'default-secret-key-change-in-production'` | Cookie session encryption key |
| `NODE_ENV` | `'development'` | Environment mode |

## Testing

- `test/metadata.test.js` — unit tests for slug generation, keyword extraction, reading time, etc.
- `test/api.test.js` — integration tests using Supertest against the Express app.
- Jest config: `jest.config.js` — node environment, 10s timeout, verbose output.

## Docker

- `Dockerfile`: Node 18-slim, production deps only, creates `data/` and `public/uploads/` dirs.
- `docker-compose.yml`: Maps `./data:/app/data` and `./public/uploads:/app/public/uploads` for persistence.
