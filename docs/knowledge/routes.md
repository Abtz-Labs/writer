# Routes

## Web Pages

| Route             | Page                                                                 | Auth Required |
| ----------------- | -------------------------------------------------------------------- | ------------- |
| `GET /`           | Post list (published only, newest first, tag filtering + pagination) | No            |
| `GET /post/:slug` | Post detail with prev/next navigation                                | No            |
| `GET /search`     | Fuzzy search across posts                                            | No            |
| `GET /onboarding` | First-time blog setup form                                           | No            |
| `GET /panel`      | Admin post list (all statuses)                                       | Session       |
| `GET /settings`   | Blog settings, credentials, custom scripts, token rotation           | Session       |
| `GET /login`      | Login form (token or username/password)                              | No            |
| `GET /docs`       | API documentation page                                               | No            |
| `GET /feed.xml`   | RSS 2.0 feed                                                         | No            |

## API Endpoints

| Method   | Path                         | Auth                                                                  |
| -------- | ---------------------------- | --------------------------------------------------------------------- |
| `GET`    | `/api`                       | No — returns API docs                                                 |
| `GET`    | `/api/posts`                 | No — paginated (`?page=&limit=`), limit capped at 100, only published |
| `GET`    | `/api/posts/:slug`           | No for published; draft requires `X-Auth-Token`                       |
| `POST`   | `/api/posts`                 | Yes — `{ title, body, tags[], cover_image?, status?, published_at? }` |
| `PUT`    | `/api/posts/:slug`           | Yes — `{ title?, body?, tags[], cover_image?, status?, published_at? }` |
| `DELETE` | `/api/posts/:slug`           | Yes — returns `confirmation_url`                                      |
| `GET`    | `/api/settings`              | No — `auth_token` is stripped from response                           |
| `POST`   | `/api/onboarding`            | No — rate limited to 5/hour                                           |
| `PUT`    | `/api/settings`              | Yes                                                                   |
| `POST`   | `/api/settings/rotate-token` | Yes — returns `confirmation_url`                                      |
| `PUT`    | `/api/settings/credentials`  | Yes — `{ username, password }`                                        |
| `POST`   | `/api/confirm/:token`        | Yes — executes pending destructive action                             |
