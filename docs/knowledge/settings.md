# Settings

## Settings Fields

| Field                 | Type    | Description                                      |
| --------------------- | ------- | ------------------------------------------------ |
| `id`                  | string  | Always `settings` (single row)                   |
| `title`               | string  | Blog title                                       |
| `author`              | string  | Author name                                      |
| `description`         | string  | Blog description                                 |
| `username`            | string  | Admin username (optional)                        |
| `password_hash`       | string  | bcrypt password hash (or a legacy hash)          |
| `recovery_key_hash`   | string  | sha256 hash of the recovery key                  |
| `auth_token`          | string  | 64-char hex API token (hidden from public API)   |
| `about`               | string  | Short author bio shown in the sidebar            |
| `custom_scripts`      | string  | Raw HTML injected into `<head>`                  |
| `csp_script_domains`  | string  | Comma-separated extra `script-src` domains       |
| `onboarding_complete` | boolean | Whether setup is done                            |
| `show_docs`           | boolean | Whether to show the API Docs link in the footer  |
| `created_at`          | string  | ISO 8601 timestamp                               |
| `updated_at`          | string  | ISO 8601 timestamp                               |

## Environment Variables

| Variable          | Default                        | Purpose                                   |
| ----------------- | ------------------------------ | ----------------------------------------- |
| `WRITER_DB_PATH`  | `writer.sqlite` beside `index.php` | Path to the SQLite database file      |
| `WRITER_OG_DIR`   | `og-images` beside `index.php`     | Directory for cached OG images        |

Keep both paths outside the release directory (for example in `shared/`) so the
database and the OG cache survive deploys.

Everything else is configured in the app: the CSP is built from defaults plus
`csp_script_domains`, and the session settings (lifetime, cookie flags) live in
`bootSession()`.

## Constants (`index.php`)

| Constant           | Value                  | Purpose                              |
| ------------------ | ---------------------- | ------------------------------------ |
| `APP_VERSION`      | `2.0.0`                | Version (mirrored in `package.json`) |
| `SESSION_LIFETIME` | `86400`                | Session length in seconds            |
| `PAGE_SIZE`        | `10`                   | Default API/page post count          |
| `GIST_CACHE_TTL`   | `3600`                 | Gist cache lifetime in seconds       |
| `ESM_ORIGIN`       | `https://esm.sh`       | Tiptap/Turndown CDN                  |
| `HLJS_ORIGIN`      | `https://cdn.jsdelivr.net` | highlight.js CDN                 |
