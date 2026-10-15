# Project Overview

Writer — a single-tenant, Medium-style blog with a RESTful API, Markdown support, and
automatic content metadata inference. Built as a **single PHP file** (`index.php`) with a
SQLite database. No frameworks, no Composer, no build step.

Writer is part of the [Use Bare](https://usebare.org) family of single-file apps.

## Stack

| Concern      | Choice                                                            |
| ------------ | ----------------------------------------------------------------- |
| Runtime      | PHP 8.1+                                                          |
| Database     | SQLite via PDO (FTS5 trigram index for search)                    |
| Templating   | PHP string builders (`renderPage()`), no template engine          |
| CSS / JS     | Inlined (`cssInline()`); Tiptap and highlight.js from a CDN       |
| OG images    | GD + FreeType with bundled DejaVu fonts                           |
| Markdown     | Pure-PHP GFM-subset parser + a tokenizer port of the `xss` filter |

## Layout

- **`index.php`** — the whole application.
- **`tests.php`** — HTTP + unit suite (self-hosted server, temporary DB).
- **`import.php`** — one-time JSON→SQLite importer (gitignored, CLI-only).
- **`assets/fonts/`** — TTFs used to render Open Graph cards.
- **`docs/`** — specification, knowledge base, plans and session memory.

Mobile-first responsive layout. On narrow screens the topic filter collapses to a single
line behind a control that expands it; from 769px up the list wraps fully and the control
is hidden.
