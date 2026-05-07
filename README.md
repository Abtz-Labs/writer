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

## Features

- **Markdown-powered posts** with metadata inference (slug, keywords, excerpt, reading time)
- **RESTful API** with API key authentication
- **Admin Panel** with web-based post management and settings
- **Tag Support** with filtering on the home page
- **Open Graph Images** — auto-generated 1200×630 social sharing cards for every post
- **RSS Feed** at `/feed.xml`
- **Custom Scripts** — inject analytics or other scripts into every page `<head>`
- **Draft/Published** workflow
- **Search** with fuzzy matching across titles, body, tags, and keywords
- **Security Hardened**:
  - scrypt password hashing with per-user salts
  - CSRF protection on all state-changing web routes
  - Rate limiting on login and onboarding endpoints
  - Timing-safe password comparison
  - Session cookies with `httpOnly`, `sameSite=strict`, and `secure` in production
  - CSP with `script-src-attr 'none'`

## API Documentation

Visit `/docs` for the full API reference, or `GET /api` for a self-discoverable JSON spec.

### Public Endpoints

| Method | Path             | Description                       |
| ------ | ---------------- | --------------------------------- |
| GET    | /api             | API documentation (JSON)          |
| GET    | /api/posts       | List published posts (paginated)  |
| GET    | /api/posts/:slug | Get a published post              |
| GET    | /api/settings    | Get blog settings (no auth_token) |
| POST   | /api/onboarding  | First-time blog setup             |

### Protected Endpoints (require `X-Auth-Token`)

| Method | Path                       | Description                               |
| ------ | -------------------------- | ----------------------------------------- |
| POST   | /api/posts                 | Create a post                             |
| PUT    | /api/posts/:slug           | Update a post                             |
| DELETE | /api/posts/:slug           | Delete a post (requires confirmation)     |
| PUT    | /api/settings              | Update settings                           |
| POST   | /api/settings/rotate-token | Rotate auth token (requires confirmation) |
| PUT    | /api/settings/credentials  | Update username/password                  |
| POST   | /api/confirm/:token        | Confirm a destructive action              |

### Destructive Action Confirmation Flow

Endpoints that delete posts or rotate tokens return `202 Accepted` with a `confirmation_url`. You must `POST` that URL to actually execute the action:

```bash
# Step 1: Request deletion
curl -X DELETE http://localhost:8080/api/posts/my-post \
  -H "X-Auth-Token: YOUR_TOKEN"
# → { "confirmation_required": true, "confirmation_url": "/api/confirm/abc123" }

# Step 2: Confirm
curl -X POST http://localhost:8080/api/confirm/abc123 \
  -H "X-Auth-Token: YOUR_TOKEN"
# → { "message": "Post deleted successfully" }
```

## Admin Panel

- `/panel` — Manage posts (create, edit, delete, search)
- `/settings` — Update blog info, credentials, custom scripts, and rotate auth token
- `/login` — Log in with username/password or auth token

## Security Notes

- Passwords are hashed with **scrypt** (salted, CPU-hard). Legacy SHA256 hashes are still supported for backward compatibility.
- The `auth_token` is a 256-bit random hex string. It is only returned during onboarding or after a successful token rotation.
- All web POST forms include CSRF tokens. API endpoints use `X-Auth-Token` and are exempt from CSRF checks.
- Draft posts are never exposed through public API or the home page.

## Testing

```bash
npm test
```

## License

MIT
