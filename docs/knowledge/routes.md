# Routes

## Web Pages

Before the blog is configured, every human-facing page (`/`, `/search`, `/post/:slug`,
`/docs`, `/panel`, `/settings`, `/login`) redirects to `/onboarding`. The API, `/feed.xml`
and `/og/*` stay reachable so machine clients and monitoring keep working.

| Route                       | Page                                                             | Auth    |
| --------------------------- | ---------------------------------------------------------------- | ------- |
| `GET /`                     | Post list (published, newest first, tag filtering + pagination)  | No      |
| `GET /search`               | Search results (`?q=`, FTS5-ranked)                              | No      |
| `GET /post/:slug`           | Post detail with prev/next navigation                            | No (draft preview with a session) |
| `GET /login`                | Login form (username/password, auth token, or recovery key)      | No      |
| `POST /login`               | Log in; redirects to `/` or `?redirect=`                         | No      |
| `POST /logout`              | Clear the session                                                | Session |
| `GET /onboarding`           | First-run setup form                                             | No      |
| `GET /docs`                 | API documentation page                                           | No      |
| `GET /panel`                | Admin post list (all statuses, search + pagination)              | Session |
| `GET /posts`                | Redirects to `/panel`                                            | Session |
| `GET /settings`             | Blog settings, credentials, custom scripts, token rotation       | Session |
| `POST /settings/update`     | No-JS fallback for the general settings form                     | Session |
| `POST /settings/update-credentials` | No-JS fallback for the credentials form                  | Session |
| `GET /feed.xml`             | RSS 2.0 feed                                                     | No      |
| `GET /og/site.png`          | Site Open Graph card                                             | No      |
| `GET /og/:slug.png`         | Post Open Graph card (published only)                            | No      |

## API Endpoints

| Method   | Path                          | Auth                                                                  |
| -------- | ----------------------------- | --------------------------------------------------------------------- |
| `GET`    | `/api/health`                 | No — `{"status":"ok"}`                                                |
| `GET`    | `/api`                        | No — self-describing API docs                                         |
| `GET`    | `/api/posts`                  | No — paginated (`?page=&limit=`), limit capped at 100, published only |
| `GET`    | `/api/posts/:slug`            | No for published; drafts need `X-Auth-Token`                          |
| `POST`   | `/api/posts`                  | Yes — `{ title, body, tags[], cover_image?, status?, published_at? }` |
| `PUT`    | `/api/posts/:slug`            | Yes — partial update                                                  |
| `DELETE` | `/api/posts/:slug`            | Yes — returns `confirmation_url`                                      |
| `GET`    | `/api/settings`               | No — strips `auth_token` and `username`                               |
| `POST`   | `/api/onboarding`             | No — rate limited to 5/hour                                           |
| `PUT`    | `/api/settings`               | Yes                                                                   |
| `PUT`    | `/api/settings/credentials`   | Yes — `{ username, password }`                                        |
| `POST`   | `/api/settings/rotate-token`  | Yes — returns `confirmation_url`                                      |
| `POST`   | `/api/confirm/:token`         | Yes — executes the pending destructive action                         |

Protected endpoints take the `X-Auth-Token` header. Destructive actions use the two-step
confirmation flow described in [Architecture](architecture.md#destructive-action-confirmation).
