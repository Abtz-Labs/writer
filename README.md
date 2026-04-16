# Writer

A simple, Medium-style blog web application with a RESTful API, Markdown support, and automatic content metadata inference.

## Quick Start

```bash
npm start
```

Then open http://localhost:8080

## API Usage

```bash
# Create a post
curl -X POST http://localhost:8080/api/posts \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"title": "Hello World", "body": "My first post content"}'
```

## Features

- Markdown-powered posts with metadata inference
- RESTful API with API key authentication
- Image uploading and processing
- Tag support
- Auto-generated slugs, keywords, excerpts, and reading time