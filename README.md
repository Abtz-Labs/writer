# Writer

A simple, single-tenant blog web application with a RESTful API, Markdown support, automatic content metadata inference, and built-in security features.

## Quick Start

```bash
npm install
npm start
```

Then open http://localhost:8080

## Configuration

Copy `.env.example` to `.env` and set your values:

```bash
PORT=8080
NODE_ENV=production
SESSION_KEY=change-this-to-a-long-random-string-min-32-chars
RATE_LIMIT_WINDOW_MS=900000
RATE_LIMIT_MAX=100
```

**Important:** Set a strong `SESSION_KEY` in production. If not set, a random key is generated on startup (which invalidates existing sessions on restart).

## Features

- **Markdown-powered posts** with metadata inference (slug, keywords, excerpt, reading time)
- **RESTful API** with token authentication and self-discoverable docs at `GET /api`
- **Admin Panel** at `/panel` — create, edit, delete, search posts
- **Settings** at `/settings` — blog info, credentials, custom scripts, token rotation
- **Tag support** with filtering on the home page
- **Open Graph images** — auto-generated 1200×630 social cards
- **RSS feed** at `/feed.xml`
- **Custom scripts** — inject analytics or other tags into `<head>`
- **Draft/Published** workflow (drafts hidden from public API)
- **Search** with fuzzy matching across titles, body, tags, and keywords
- **Security hardened** — scrypt hashing, CSRF, rate limiting, timing-safe comparisons, secure cookies, CSP

## API Usage

```bash
# Create a post
curl -X POST http://localhost:8080/api/posts \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"title": "Hello World", "body": "My first post content"}'

# List published posts
curl http://localhost:8080/api/posts?page=1&limit=10

# Get a single post
curl http://localhost:8080/api/posts/my-post-slug
```

For the full API reference, visit `/docs` in-app or see the [specification](docs/SPEC.md#5-api-specification).

## Testing

```bash
npm test
```

## Documentation

- [Full specification](docs/SPEC.md) — design, data models, endpoints, acceptance criteria
- [Knowledge base](docs/knowledge/) — architecture, routes, deployment, and more

## License

MIT
