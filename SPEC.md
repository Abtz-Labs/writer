# Blog WebApp Specification

## 1. Project Overview

- **Project Name**: Serif Blog
- **Type**: Single-tenant blog web application
- **Core Functionality**: A clean, Medium-style blog platform with RESTful API, Markdown support, automatic content metadata inference, OG image generation, and RSS feed
- **Target Users**: Bloggers who want a simple, elegant writing experience with an AI-consumable API

## 2. Tech Stack

- **Runtime**: Node.js 18.x LTS
- **Framework**: Express.js
- **Templating**: EJS
- **Database**: JSLiteDB (https://github.com/Abtz-Labs/jslitedb)
- **Image Generation**: @napi-rs/canvas (zero-dependency Skia)
- **Containerization**: Docker

## 3. UI/UX Specification

### Layout Structure

**Header**
- Logo/Site title (left-aligned)
- Navigation links (right-aligned): Home, About (if configured)
- Minimal height, sticky on scroll

**Home Page (Post List)**
- Single column layout, centered, max-width 700px
- Post cards with: title, excerpt, publication date, reading time, optional thumbnail (first image from post)
- Pagination (10 posts per page)
- Tag filtering

**Post Detail Page**
- Title (h1), large font
- Publication date, reading time
- Author name (if configured)
- Body content (prose styling)
- Tags at bottom
- Prev/next post navigation
- Open Graph meta tags for social sharing

**Admin Panel**
- Post list table with search, pagination (15 per page)
- Inline post create/edit form with Markdown toolbar
- Confirmation modal for deletes (no native `confirm()`)
- Notification banners below navbar (sticky, visible on scroll)

**Onboarding Page**
- Clean form: Blog title, Author name, Description, Username, Password
- Generates auth token
- Instructions for API usage

### Visual Design

**Color Palette**
- Background: `#ffffff` (white)
- Text Primary: `#292929` (near black)
- Text Secondary: `#6b6b6b` (gray)
- Accent: `#1a8917` (Medium green)
- Link: `#1a8917`
- Border: `#eee`
- Card Hover: `#fafafa`

**Typography**
- Headings: `Georgia`, serif
- Body: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`
- Code: `Menlo, Monaco, "Courier New", monospace`
- Base size: 18px
- Line height: 1.6
- Heading sizes: h1=2.5rem, h2=2rem, h3=1.5rem

**Spacing**
- Container max-width: 700px
- Section padding: 2rem
- Paragraph margin: 1.5rem
- Card padding: 1.5rem

### Components

**Post Card**
- Optional thumbnail image (16:9 aspect ratio, object-fit cover)
- Title (clickable, green on hover)
- Excerpt (2-3 lines, truncated)
- Meta: date + reading time
- Subtle bottom border

**Article Body**
- Proper paragraph spacing
- Blockquotes with left border
- Code blocks with background
- Images (responsive, max-width 100%)
- Lists, headings properly styled

## 4. Functionality Specification

### Core Features

**1. Onboarding System**
- First visit triggers onboarding form
- Configures: blog title, author name, description, username, password
- Generates random auth token: `crypto.randomBytes(32).toString('hex')`
- Stores token in database
- Returns token to user with instructions
- Rate limited to 5 attempts per hour

**2. Authentication**
- API endpoints (except public GETs) require `X-Auth-Token` header
- Web admin routes require session authentication via `/login`
- Two login methods: auth token directly, or username/password
- Passwords hashed with scrypt (salted, CPU-hard)
- Invalid token returns 401
- Login rate limited to 5 attempts per 15 minutes

**3. Post Management (API)**
- `GET /api/posts` — List published posts only (public, paginated, limit capped at 100)
- `GET /api/posts/:slug` — Get single post (public for published; drafts require auth)
- `POST /api/posts` — Create post (auth required)
  - Payload: `{ title, body, tags[], cover_image?, status? }`
  - Auto-generates: slug, keywords, meta_description, reading_time, excerpt
  - Image processing: find URLs in body, download, replace with local paths
- `PUT /api/posts/:slug` — Update post (auth required)
- `DELETE /api/posts/:slug` — Delete post (auth required, requires confirmation via `/api/confirm/:token`)

**4. Content Metadata Inference**
- **Slug**: From title (lowercase, hyphens, remove special chars)
- **Keywords**: Extract from title + first 200 chars of body
- **Meta Description**: First 160 chars of body
- **Reading Time**: Words / 200 (average reading speed)
- **Excerpt**: First 200 chars of plain text

**5. Image Processing**
- Scan body for `http(s)://*.jpg`, `http(s)://*.png`, `http(s)://*.gif`
- Download image, store locally in `/public/uploads/`
- Replace URL with `/uploads/filename.jpg`
- Support: jpg, png, gif, webp

**6. Settings Management (API)**
- `GET /api/settings` — Get blog settings (public, `auth_token` stripped)
- `PUT /api/settings` — Update settings (auth required)
  - Payload: `{ title, author, description, custom_scripts, show_docs }`
- `PUT /api/settings/credentials` — Update username/password (auth required)
- `POST /api/settings/rotate-token` — Rotate auth token (auth required, requires confirmation)

**7. Self-Discoverable API**
- `GET /api` — Returns API documentation
  - Available endpoints
  - Required headers
  - Expected payloads
  - Response formats

**8. Open Graph Image Generation**
- `GET /og/:slug.png` — Generates 1200×630 PNG card for a post
- `GET /og/site.png` — Generates site-wide OG card
- Dark theme with auto-wrapping title and author byline
- Cached in `public/og-images/` with 24h HTTP cache headers
- Cache invalidated on post update/delete and settings change

**9. RSS Feed**
- `GET /feed.xml` — Valid RSS 2.0 feed
- Channel: title, link, description, language, lastBuildDate
- Items: up to 20 published posts with title, link, description (CDATA), pubDate, guid
- 1h HTTP cache headers

**10. Custom Scripts**
- Admin can inject raw HTML/script tags into every page `<head>`
- Rendered unescaped in `views/layout.ejs`
- Useful for analytics, custom fonts, etc.

### Data Models

**Post**
```json
{
  "id": "uuid",
  "title": "string",
  "slug": "string",
  "body": "string",
  "excerpt": "string",
  "cover_image": "string",
  "keywords": ["array"],
  "meta_description": "string",
  "reading_time": "number",
  "status": "draft | published",
  "tags": ["array"],
  "created_at": "timestamp",
  "updated_at": "timestamp"
}
```

**Settings**
```json
{
  "id": "settings",
  "title": "string",
  "author": "string",
  "description": "string",
  "username": "string",
  "password_hash": "string",
  "auth_token": "string",
  "custom_scripts": "string",
  "onboarding_complete": "boolean",
  "show_docs": "boolean",
  "created_at": "timestamp",
  "updated_at": "timestamp"
}
```

### Edge Cases
- Empty title/body: Return 400 with validation error
- Duplicate slug: Append `-1`, `-2`, etc.
- Image download fails: Keep original URL, log warning
- Invalid auth token: Return 401, include hint
- Draft access without auth: Return 404 (not 403, to avoid leaking existence)
- Confirmation token expired: Return 410 Gone
- Database corruption: Auto-backup, recovery option

## 5. API Specification

### Public Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | / | Home page (HTML) |
| GET | /post/:slug | Post detail (HTML) |
| GET | /search | Search page (HTML) |
| GET | /onboarding | Onboarding page (HTML) |
| GET | /api | API documentation (JSON) |
| GET | /api/posts | List published posts |
| GET | /api/posts/:slug | Get published post |
| GET | /api/settings | Get settings (no auth_token) |
| GET | /feed.xml | RSS 2.0 feed |
| GET | /og/site.png | Site OG image |
| GET | /og/:slug.png | Post OG image |

### Protected Endpoints (Require X-Auth-Token)

| Method | Path | Description |
|--------|------|-------------|
| POST | /api/posts | Create post |
| PUT | /api/posts/:slug | Update post |
| DELETE | /api/posts/:slug | Delete post (requires confirmation) |
| PUT | /api/settings | Update settings |
| PUT | /api/settings/credentials | Update credentials |
| POST | /api/settings/rotate-token | Rotate token (requires confirmation) |
| POST | /api/confirm/:token | Confirm destructive action |

## 6. Project Structure

```
/blog
├── Dockerfile
├── docker-compose.yml
├── package.json
├── .dockerignore
├── README.md
├── SPEC.md
├── CLAUDE.md
├── AGENTS.md
├── app.js
├── .env.example
├── /config
│   └── database.js
├── /controllers
│   ├── postController.js
│   └── settingsController.js
├── /middleware
│   ├── auth.js
│   ├── webAuth.js
│   └── errorHandler.js
├── /models
│   ├── post.js
│   └── settings.js
├── /routes
│   ├── api.js
│   └── web.js
├── /services
│   ├── confirmation.js
│   ├── imageProcessor.js
│   ├── metadata.js
│   └── ogImage.js
├── /utils
│   └── logger.js
├── /views
│   ├── /pages
│   │   ├── index.ejs
│   │   ├── post.ejs
│   │   ├── search.ejs
│   │   ├── onboarding.ejs
│   │   ├── login.ejs
│   │   ├── panel.ejs
│   │   ├── settings.ejs
│   │   ├── docs.ejs
│   │   └── 404.ejs
│   ├── /partials
│   │   ├── header.ejs
│   │   └── footer.ejs
│   └── layout.ejs
├── /public
│   ├── /uploads
│   ├── /og-images
│   └── /css
│       └── style.css
└── /test
    ├── api.test.js
    └── metadata.test.js
```

## 7. Acceptance Criteria

1. **Onboarding**: First visit shows onboarding form; after submission, auth token is generated and displayed
2. **Authentication**: All protected API endpoints reject requests without valid token
3. **Post Creation**: POST /api/posts with title+body creates post with all inferred metadata
4. **Image Processing**: Body URLs are downloaded and replaced with local paths
5. **Self-Discovery**: GET /api returns complete API documentation
6. **UI**: Home page shows post list; post page shows full article with proper styling
7. **OG Images**: Social sharing cards are generated for posts and the site
8. **RSS Feed**: /feed.xml returns valid RSS 2.0 with published posts
9. **Security**: Passwords use scrypt, CSRF tokens on web forms, rate limiting on login/onboarding, drafts hidden from public API
10. **Docker**: Application builds and runs in Docker container
11. **TDD**: Tests exist for core functionality (metadata inference, auth, API, confirmation flow)

## 8. Development Approach

1. Write tests first (TDD)
2. Implement database layer
3. Implement core services (metadata, image processing, OG images, confirmation)
4. Build API routes
5. Create EJS templates
6. Add Docker configuration
7. Verify all acceptance criteria
