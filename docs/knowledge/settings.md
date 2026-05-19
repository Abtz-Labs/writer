# Settings

## Settings Fields

| Field                 | Type    | Description                                    |
| --------------------- | ------- | ---------------------------------------------- |
| `title`               | string  | Blog title                                     |
| `author`              | string  | Author name                                    |
| `description`         | string  | Blog description                               |
| `username`            | string  | Admin username (optional)                      |
| `password_hash`       | string  | scrypt-hashed password                         |
| `auth_token`          | string  | 64-char hex API token (hidden from public API) |
| `custom_scripts`      | string  | Raw HTML/scripts injected into `<head>`        |
| `onboarding_complete` | boolean | Whether setup is done                          |
| `show_docs`           | boolean | Whether to show API Docs link in footer        |

## Environment Variables

| Variable               | Default            | Purpose                                            |
| ---------------------- | ------------------ | -------------------------------------------------- |
| `PORT`                 | `8080`             | HTTP server port (also settable via `-p` CLI flag) |
| `SESSION_KEY`          | random 32-byte hex | Cookie session encryption key                      |
| `NODE_ENV`             | `'development'`    | Environment mode                                   |
| `RATE_LIMIT_WINDOW_MS` | `900000`           | Global rate limit window (15 min)                  |
| `RATE_LIMIT_MAX`       | `100`              | Global rate limit max requests per window          |
