# Blog WebApp Specification

## 1. Project Overview

- **Project Name**: Serif Blog
- **Type**: Single-tenant blog web application
- **Core Functionality**: A clean, Medium-style blog platform with RESTful API, Markdown support, and automatic content metadata inference
- **Target Users**: Bloggers who want a simple, elegant writing experience with an AI-consumable API

## 2. Tech Stack

- **Runtime**: Node.js 18.x LTS
- **Framework**: Express.js
- **Templating**: EJS
- **Database**: JSLiteDB (https://github.com/Abtz-Labs/jslitedb)
- **Containerization**: Docker

## 3. UI/UX Specification

### Layout Structure

**Header**
- Logo/Site title (left-aligned)
- Navigation links (right-aligned): Home, About (if configured)
- Minimal height, sticky on scroll

**Home Page (Post List)**
- Single column layout, centered, max-width 700px
- Post cards with: title, excerpt, publication date, reading time
- Infinite scroll or pagination

**Post Detail Page**
- Title (h1), large font
- Publication date, reading time
- Author name (if configured)
- Body content (prose styling)
- Tags at bottom

**Onboarding Page**
- Clean form: Blog title, Author name
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
- Configures: blog title, author name
- Generates MD5-based auth token: `token = md5(blog_title + timestamp)`
- Stores token in database
- Returns token to user with instructions

**2. Authentication**
- All API endpoints (except GET /) require `X-Auth-Token` header
- Token validated against stored value
- Invalid token returns 401

**3. Post Management (API)**
- `GET /api/posts` - List all posts (public, paginated)
- `GET /api/posts/:slug` - Get single post (public)
- `POST /api/posts` - Create post (auth required)
  - Payload: `{ title, body, tags[] }`
  - Auto-generates: slug, keywords, meta_description, reading_time
  - Image processing: find URLs in body, download, replace with base64 or local path
- `PUT /api/posts/:slug` - Update post (auth required)
- `DELETE /api/posts/:slug` - Delete post (auth required)

**4. Content Metadata Inference**
- **Slug**: From title (lowercase, hyphens, remove special chars)
- **Keywords**: Extract from title + first 200 chars of body
- **Meta Description**: First 160 chars of body
- **Reading Time**: Words / 200 (average reading speed)

**5. Image Processing**
- Scan body for `http(s)://*.jpg`, `http(s)://*.png`, `http(s)://*.gif`
- Download image, store locally in `/public/uploads/`
- Replace URL with `/uploads/filename.jpg`
- Support: jpg, png, gif, webp

**6. Settings Management (API)**
- `GET /api/settings` - Get blog settings (public)
- `PUT /api/settings` - Update settings (auth required)
  - Payload: `{ title, author, description }`

**7. Self-Discoverable API**
- `GET /api` - Returns API documentation
  - Available endpoints
  - Required headers
  - Expected payloads
  - Response formats

### Data Models

**Post**
```json
{
  "id": "uuid",
  "title": "string",
  "slug": "string",
  "body": "string",
  "excerpt": "string",
  "keywords": "string",
  "meta_description": "string",
  "reading_time": "number",
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
  "auth_token": "string",
  "onboarding_complete": "boolean",
  "created_at": "timestamp"
}
```

### Edge Cases
- Empty title/body: Return 400 with validation error
- Duplicate slug: Append `-1`, `-2`, etc.
- Image download fails: Keep original URL, log warning
- Invalid auth token: Return 401, include hint
- Database corruption: Auto-backup, recovery option

## 5. API Specification

### Public Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | / | Home page (HTML) |
| GET | /post/:slug | Post detail (HTML) |
| GET | /onboarding | Onboarding page (HTML) |
| GET | /api | API documentation (JSON) |
| GET | /api/posts | List posts |
| GET | /api/posts/:slug | Get post |
| GET | /api/settings | Get settings |

### Protected Endpoints (Require X-Auth-Token)

| Method | Path | Description |
|--------|------|-------------|
| POST | /api/posts | Create post |
| PUT | /api/posts/:slug | Update post |
| DELETE | /api/posts/:slug | Delete post |
| PUT | /api/settings | Update settings |

## 6. Project Structure

```
/blog
├── Dockerfile
├── docker-compose.yml
├── package.json
├── .dockerignore
├── README.md
├── app.js
├── /src
│   ├── /config
│   │   └── database.js
│   ├── /controllers
│   │   ├── postController.js
│   │   └── settingsController.js
│   ├── /middleware
│   │   ├── auth.js
│   │   └── errorHandler.js
│   ├── /models
│   │   ├── post.js
│   │   └── settings.js
│   ├── /routes
│   │   ├── api.js
│   │   └── web.js
│   ├── /services
│   │   ├── metadata.js
│   │   └── imageProcessor.js
│   └── /utils
│       └── helpers.js
├── /views
│   ├── /partials
│   │   ├── header.ejs
│   │   └── footer.ejs
│   ├── index.ejs
│   ├── post.ejs
│   └── onboarding.ejs
└── /public
    ├── /uploads
    └── css
        └── style.css
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
7. **Docker**: Application builds and runs in Docker container
8. **TDD**: Tests exist for core functionality (metadata inference, auth, API)

## 8. Development Approach

1. Write tests first (TDD)
2. Implement database layer
3. Implement core services (metadata, image processing)
4. Build API routes
5. Create EJS templates
6. Add Docker configuration
7. Verify all acceptance criteria