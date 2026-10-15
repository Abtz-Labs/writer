# 2026-10-16 — Move the version from the footer to the login page

## Outcome

The Writer version is no longer in the global footer. It now shows only on the login
page, centered, just below the form card and outside it. `php tests.php`: 480 PASS / 0
FAIL / 1 SKIP.

## Change

- `viewFooter()` (`index.php`): dropped `  #<APP_VERSION>`; the line is now just
  `<p>Powered by <a class="brand">Writer</a></p>`.
- `viewLogin()` (`index.php`): after `</form>`, appended
  `<p class="login-version">Writer #<APP_VERSION></p>` — outside the `.box` card.
- New rule `.login-version` next to `#loginForm` in `cssInline()`: `margin: 0`,
  `text-align: center`, `font-size: 0.85rem`, `color: var(--color-text-light)`.
  The `.box` `margin-bottom: var(--spacing-lg)` supplies the consistent gap.

## Notes

- Version format kept as the shipped `#2.0.0` (the footer had drifted from the test's
  expected `v.2.0.0`). The `Footer Credits` test was asserting the stale `v.` format and
  had been failing on `main`; it now asserts the version is **absent** from the footer.
- `Web Login` group gained two assertions: the `.login-version` element exists and it
  contains `Writer #<APP_VERSION>`.
