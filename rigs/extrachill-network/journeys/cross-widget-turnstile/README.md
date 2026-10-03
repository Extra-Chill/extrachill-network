# Cross-widget Turnstile isolation journey

Ported from this repo's own `tests/browser/` (a standalone single-plugin
wp-codebox recipe with its own shell runner, `run-cross-widget-smoke.sh`)
onto the network rig's journey contract (extrachill-network#293). The
single-plugin recipe, its bespoke `artifacts/` directory, and the shell
runner's Python-based post-run parsing are all dropped; this journey runs as
part of the same full 11-site boot every other journey does.

## Why this exists

`ec_render_turnstile_widget()` emits `<div class="cf-turnstile">` elements.
Historically these were rendered by Cloudflare's `api.js` **implicit
auto-render pass** -- a single loop over every `.cf-turnstile` element on the
page. If one widget carried a `data-callback` attribute naming a JS function
that was never defined, that loop **threw while processing that widget and
aborted for every widget on the page**. An unrelated sibling widget (e.g. the
event-submission captcha on a page that also carries the footer newsletter
form) then silently never rendered -- newsletter issue #17 and multisite #48
("the lackey bug").

The fix (multisite #48) switched the shared primitive from implicit
auto-render to **explicit per-widget render**:
`ec_enqueue_turnstile_script()` loads api.js with
`?render=explicit&onload=ecTurnstileBoot` and ships a tiny boot script
(`assets/js/turnstile-boot.js`) that renders **each** `.cf-turnstile` widget
in its own `turnstile.render()` call wrapped in try/catch. A bad widget can
now only break itself.

`tests/TurnstileTest.php` covers the PHP renderer in isolation. The
*cross-widget render contract* is a DOM + JS behaviour that only manifests
with multiple real widgets co-rendering in a browser -- this journey covers
that layer, exercising the plugin's **real** boot script.

## What it does

`seed.php` configures Cloudflare's documented "always passes" Turnstile TEST
keys (`1x00000000000000000000AA` /
`1x0000000000000000000000000000000AA`; see
[Cloudflare's testing docs](https://developers.cloudflare.com/turnstile/troubleshooting/testing/))
purely so `ec_render_turnstile_widget()` emits real widget markup, renders
**two** widgets via the plugin's own function -- the **first** deliberately
broken (a `data-callback` naming an undefined global, the exact lackey-bug
config), the **second** well-formed -- then loads the plugin's **actual**
`assets/js/turnstile-boot.js` over a faithful stub of `window.turnstile`
that throws on the broken widget's config (mirroring Cloudflare rejecting an
invalid widget). The boot's per-widget try/catch must contain that throw and
keep rendering the good sibling.

The single browser step navigates to the seeded page and asserts
`no-page-errors`: the isolation contract's decisive safety property is that
the bad widget's throw is caught by the boot's own try/catch and never
escapes as an uncaught page error (under the old implicit batch, this exact
scenario produced an uncaught error and aborted rendering entirely).
`grade.php` verifies the server-side truth the browser exercised against:
the seeded page still carries exactly two widgets, one still declaring the
dangling callback, driven by the plugin's real (never reimplemented) boot
script, with only the documented test key configured.

## What is not (yet) an automated in-journey gate

The old `run-cross-widget-smoke.sh` additionally parsed the recipe run's own
`console.jsonl` artifact for an `EC_TURNSTILE_SMOKE rendered=<n> total=<n>`
marker the seed's stub emits, requiring `rendered >= 1` of `total == 2` (at
least the good widget rendered, not just "no error"). That parsing happens
on the **host**, after the boot finishes, over an artifact this journey's
own `wordpress.run-php` grade step -- running *inside* the sandbox -- cannot
read. This journey verifies that half of the contract as part of its own
evidence-gathering pass against a real `homeboy rig up` run (see
`evidence/FINDINGS.md` and `evidence/console.jsonl`), the same way every
other journey's evidence is compiled, rather than claiming an automated gate
this contract does not yet provide. A generic "assert a console marker
appeared" rig capability would close this gap for every journey, not just
this one; nothing in this port needed it badly enough to justify adding it
speculatively (see the "check what already exists" / "premature
consolidation" rules).

## No persona

This is a technical, single-scenario smoke, not a persona journey -- it
carries no `persona` key.
