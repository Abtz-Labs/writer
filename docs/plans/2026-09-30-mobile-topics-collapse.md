status: done
---

# Mobile Topics Collapse

On mobile the Topics block sits above the post list (`.sidebar-column` has
`order: -1`). As post count grows, the wrapped tag list pushes posts below the
fold. Fix: on mobile only, collapse the tag row to a single clipped line with a
`…` pill pinned at the right edge. The pill expands the list; when expanded the
pill becomes a `Show less` control at the end of the list.

Desktop (>= 769px) is unchanged: the tag list stays fully wrapped and the
control is hidden.

## 1. Shared partial

New file `views/partials/topics.ejs`. Markup plus its own inline script, matching
the existing `views/partials/modals.ejs` pattern (markup + script in one
partial). Included by `views/pages/index.ejs` and `views/pages/search.ejs`, which
currently duplicate the block (`index.ejs:114-133`, `search.ejs:120-134`).

Active-tag state differs between the two pages, so the partial keys off whether
`activeTag` exists at all:

| Page          | `activeTag`      | Result                    |
| ------------- | ---------------- | ------------------------- |
| `/` no filter | `""`             | `All` marked `is-active`  |
| `/?tag=x`     | `"x"`            | `x` marked `is-active`    |
| `/search`     | not passed       | nothing marked `is-active`|

That is why `routes/web.js:254` changes `activeTag: tag || null` to
`activeTag: tag || ""`. With `null`, the partial cannot tell "no filter on the
index" from "the search page has no active state". `""` is falsy, so the
pagination links at `index.ejs:54,64` behave exactly as before.

## 2. Markup

`.tag-list` gains a trailing button:

```html
<button type="button" class="tag tag-list-toggle"
        aria-controls="tag-list" aria-expanded="false">
    <span class="tag-list-more" aria-hidden="true">…</span>
    <span class="tag-list-more-text visually-hidden">Show all topics</span>
    <span class="tag-list-less">Show less</span>
</button>
```

The visible glyph is `aria-hidden` and a visually hidden label supplies the
accessible name, so the two states read as "Show all topics" and "Show less".
CSS swaps the two states; JS only flips `aria-expanded`.

Because the button carries `class="tag"`, the existing tag click handlers must
stop matching it: `.tag-list .tag` becomes `.tag-list a.tag` in the scripts at
`index.ejs:158` and `search.ejs:156`.

## 3. CSS (`public/css/style.css`)

Mobile-first, so the collapse rules are the base rules and the desktop block at
line 1832 opts out. Two edits.

Base (replaces `.tag-list` at lines 314-321, keeps `flex-wrap` out of the
collapsed row):

- `.tag-list` — `flex-wrap: nowrap`, `overflow: hidden`, `position: relative`,
  `align-items: center`, and `--topics-toggle-width: 2.5rem` so the fade and the
  control cannot drift apart
- `.tag-list.is-expanded` — `flex-wrap: wrap`, `overflow: visible`
- `.tag-list::after` — fade to `--color-bg`, `width: calc(var(--topics-toggle-width) * 2)`
  and reaching full opacity at `var(--topics-toggle-width)`, so the ramp sits
  entirely to the *left* of the control and chips are fully hidden by the time
  they reach it. Hidden by `.tag-list.is-expanded::after`
- `.tag-list a.tag` — `flex: 0 0 auto; white-space: nowrap; max-width: 100%` so
  pills do not squash and long tags stay on one line;
  `.tag-list.is-expanded a.tag` returns to `white-space: normal`
- `.tag-list-toggle` — `position: absolute` at `right: 0`, vertically centred,
  `background: var(--color-bg)`, `width: var(--topics-toggle-width)`,
  `font-family: inherit`. `border-color: var(--color-text-light)` and
  `color: var(--color-text)` so the control reads as a control and not as another
  topic chip, which uses the much lighter `var(--color-border)`.
  `.tag-list.is-expanded .tag-list-toggle` goes back to `position: static`, drops
  the transform and sets `width: auto` so "Show less" is not clipped
- `.tag-list-toggle[hidden]` — `display: none`, needed because author rules beat
  the user-agent `[hidden]` rule
- `.visually-hidden` utility class

Inside `@media (min-width: 769px)`, extending the existing `.tag-list` rule at
lines 1886-1888:

- `.tag-list` — `flex-wrap: wrap`, `overflow: visible`, `position: static`
- `.tag-list::after`, `.tag-list-toggle` — `display: none`

Scoping the `white-space` rules to `.tag-list` leaves `.post-tags` in
`post.ejs:41-45` untouched.

## 4. Script

Inside the new partial, an IIFE in the existing style. It no-ops on pages
without the block.

- click toggles `is-expanded` on the list and `aria-expanded` on the button
- `syncToggle()` sets `button.hidden` when the row does not overflow
  (`scrollWidth <= clientWidth`) and the list is collapsed, so a blog with one or
  two topics shows no control
- `syncToggle()` runs on load and on `resize`

CSP allows inline `<script>` but blocks `on*` attributes
(`app.js:84-100`, `scriptSrcAttr: ["'none'"]`), so this uses `addEventListener`.
No build step, no `public/js/`.

## 5. Tests

New file `test/topics.test.js`, supertest against `app.js` like the rest of the
suite. These assert the rendered contract, not the CSS, which jsdom is not
available to check (vitest runs `environment: 'node'`).

- `GET /` contains `class="tag tag-list-toggle"`, `aria-expanded="false"` and
  `aria-controls="tag-list"`
- `GET /` renders every tag anchor
- `GET /search` contains the same control
- `GET /search` marks no tag `is-active` (the regression guard for the
  `activeTag` contract in step 1)
- `GET /` with no `?tag=` marks `All` `is-active`

## Verification

```
npx prettier --write views/partials/topics.ejs views/pages/index.ejs \
    views/pages/search.ejs public/css/style.css routes/web.js test/topics.test.js
npm test
```

Then check `http://localhost:8080/` and `/search` at a narrow viewport with
enough tags to overflow: collapsed shows one clipped line plus the `…` pill;
clicking expands and turns the pill into `Show less`; clicking again collapses.
At >= 769px the pill is gone and the list is fully wrapped.
