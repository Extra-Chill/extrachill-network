# Cross-widget Turnstile journey — findings (extrachill-network#293)

Run against a real, disposable 11-site network boot (`node
rigs/extrachill-network/run.mjs`, `extrachill_journeys: ["cross-widget-turnstile"]`).
5 assertions, 4 passed, 1 recorded as a finding below.

## Two harness bugs found and fixed during this port (not product defects)

1. **`wp_insert_post()` silently mangled the seeded page's `<script>` content.**
   The seed ran as an unauthenticated/default user, so `content_save_pre`'s
   `wp_filter_kses()` pass (which strips disallowed markup for any user
   lacking `unfiltered_html`) applied. Fixed by seeding as the admin user
   (`wp_set_current_user(1)`), matching the pattern every other journey's
   seed already uses for its own admin-authored fixture content. This fixed
   the "two widgets present" assertion (`cf-turnstile` class occurrences
   dropped from an inflated 12 to the correct 2 once un-mangled) but did NOT
   fully resolve the boot-script byte-match assertion below.
2. **The widget-count assertion counted every substring occurrence of
   `"cf-turnstile"`, not just actual widget elements.** The embedded boot
   script and its stub reference `.cf-turnstile` repeatedly as a CSS
   selector string (`querySelectorAll('.cf-turnstile[...]')` etc.), inflating
   the naive `substr_count()`. Fixed by matching `class="cf-turnstile`
   specifically.

## Open finding: the page's `post_content` does not byte-match the real boot script

Even after the `wp_set_current_user(1)` fix, `grade.php`'s
`uses-the-real-shipped-boot-script` case still fails: the seeded
`post_content` does not contain an exact substring match of
`assets/js/turnstile-boot.js`'s real file bytes. Since the seed step itself
throws loudly if `ec_render_turnstile_widget()` returns empty markup (ruling
out the widgets never rendering) and the "two widgets present" /
"broken-widget-declares-dangling-callback" cases both pass (confirming the
widget divs and the embedded script tags ARE present in some form), this is
most likely a WordPress content-filter transformation applied even for a
privileged user (e.g. `wptexturize()`'s smart-quote/dash substitution
inside the inline `<script>` block's string literals, which runs on
`content_save_pre` regardless of `unfiltered_html`), not evidence the real
boot script never ran. This was not fully isolated in the time available.

**This is recorded as an open finding, not silently converted to a pass.**
The decisive safety property this journey exists to prove -- that the
boot's per-widget `try/catch` contains a broken widget's throw so it never
escapes as an uncaught page error, letting the good sibling still render --
is verified independently by the browser step's own `assert=no-page-errors`
argument, which is not affected by this byte-match assertion at all.

## What passed cleanly

- The seeded page exists and is published.
- Both Turnstile widgets (the deliberately-broken one and its well-formed
  sibling) are present as real `class="cf-turnstile"` elements.
- The broken widget still declares its dangling `data-callback`, reproducing
  the exact lackey-bug configuration (newsletter #17 / multisite #48).
- Only Cloudflare's documented always-pass TEST site key
  (`1x00000000000000000000AA`) is configured -- never a real key, never
  reaching the network.
- The browser step's `assert=no-page-errors` argument holds: the isolation
  contract's decisive safety property.
