# Cross-widget Turnstile browser smoke

A real-browser smoke for the Cloudflare Turnstile integration, focused on the
**cross-widget isolation** guarantee that PHPUnit cannot reach.

## Why this exists

`ec_render_turnstile_widget()` emits `<div class="cf-turnstile">` elements.
Historically these were rendered by Cloudflare's `api.js` **implicit
auto-render pass** — a single loop over every `.cf-turnstile` element on the
page. If one widget carried a `data-callback` attribute naming a JS function
that was never defined, that loop **threw while processing that widget and
aborted for every widget on the page**. An unrelated sibling widget (e.g. the
event-submission captcha on a page that also carries the footer newsletter
form) then silently never rendered. That is exactly what shipped as newsletter
issue #17 and what took down event submission for the community user "lackey".

The fix (multisite #48) switched the shared primitive from implicit auto-render
to **explicit per-widget render**: `ec_enqueue_turnstile_script()` now loads
api.js with `?render=explicit&onload=ecTurnstileBoot` and ships a tiny boot
script (`assets/js/turnstile-boot.js`) that renders **each** `.cf-turnstile`
widget in its own `turnstile.render()` call wrapped in `try/catch`. A bad
widget can now only break itself.

The PHPUnit suite (`tests/TurnstileTest.php`) covers the PHP renderer in
isolation — including a guard that the renderer never injects an unsolicited
`data-callback`. But the *cross-widget render contract* is a DOM + JS behaviour
that only manifests with multiple real widgets co-rendering in a browser. This
smoke covers that layer, exercising the plugin's **real** boot script.

## What it does

`run-cross-widget-smoke.sh` drives the WP Codebox (Playground) runtime:

1. Boots a disposable WordPress and activates `extrachill-network`.
2. Runs `seed-two-widgets.php`, which renders **two** `.cf-turnstile` widgets via
   the plugin's own `ec_render_turnstile_widget()`. The **first** widget is
   deliberately broken — it carries a `data-callback` naming an undefined global
   (the exact lackey-bug config). The **second** is well-formed. The seed then
   loads the plugin's **actual** `assets/js/turnstile-boot.js` over a faithful
   stub of `window.turnstile.render()` that throws on the broken widget's config
   (mirroring Cloudflare rejecting an invalid widget). The boot's per-widget
   `try/catch` must contain that throw and keep rendering the good sibling.
3. Opens the seeded page in a headless browser (`wordpress.browser-probe`) and
   captures console, page errors, and a screenshot.

## What it asserts

- The page **loads and renders** without a navigation/PHP failure.
- The seed reports **two** widgets.
- The browser produced a **screenshot** (durable render evidence).
- **When the WP Codebox build captures page console/errors:** the isolation
  contract — **zero uncaught page errors** (the boot's `try/catch` contained the
  bad widget's throw; under the old implicit batch this scenario produced an
  uncaught error and aborted everything) **and at least the good widget
  rendered** (`rendered >= 1` of `total == 2`) via the `EC_TURNSTILE_SMOKE
  rendered=<n> total=<n>` console marker the seed emits. Under the old implicit
  render this exact scenario produced `rendered == 0` — both widgets aborted. On
  builds whose browser runtime does not yet capture console/errors, this final
  assertion is **skipped with a NOTE rather than producing a false green**; the
  seed and runner are already wired so it lights up automatically once capture
  (or the richer `wordpress.browser-actions` expect/assert steps) is available.

## Running

```bash
tests/browser/run-cross-widget-smoke.sh
```

Requires the `wp-codebox` CLI on `PATH`. Artifacts (including the screenshot)
are written to `tests/browser/artifacts/` and are git-ignored.
