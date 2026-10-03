# Gardner Studio social-operator journey

Ported from `Extra-Chill/extrachill-studio` `tests/wp-codebox/` (a standalone
single-plugin wp-codebox recipe with its own `wp core multisite-convert`
bootstrap, adversarial-exploration extension, and typed `wp_upload_dir()`
artifact ledgers) onto this rig's journey contract (extrachill-network#293).
Studio's own `tests/wp-codebox/` directory, its short smoke recipe
(`chris-gardner-persona.json` + `-persona-seed.php`), and the deep
stateful-operator recipe are all dropped; Studio no longer keeps any
scenario, runner, persona fixture, or journey workflow of its own.

## Canonical persona

This journey pins `extra-chill-users/chris-gardner@1.0.0`, the same
canonical identity contract `gardner-event-rsvp` uses -- both journeys force
the SAME user ID (201) for Gardner, since multisite users are global and
this is the same conceptual person, not a coincidence. See
`../../personas/README.md`.

## What moved, and what changed

| Old file | New home | What changed |
| --- | --- | --- |
| `chris-gardner-social-operator.json` workflow steps 1-6 (`wp core multisite-convert`, network-activate Network/API/Users/Analytics, `wordpress.plugin-state activate` Studio) | (deleted) | This rig already boots a real 11-site multisite network with all of these as real network- or per-site-active components (see `components.json`); nothing here needs to be reconstructed per-recipe. |
| `chris-gardner-social-operator-setup.php` | `seed.php` | Same fixture (operator/ordinary users, Instagram/Bluesky fixture accounts, canonical article + pending multiplatform draft with a pre-approval caption/media edit, Studio front-page). Runs on the real `studio.extrachill.com` site via `switch_to_blog()` + the same per-site plugin bootstrap `gardner-event-rsvp` documents for the events site, instead of a single-site sandbox. |
| `chris-gardner-social-operator-deliver.php` | `deliver.php` (a plain `wordpress.run-php` journey step) | Same scenario matrix (grant/denial boundaries at the custom REST and durable-ability layers, WordPress Core future-post scheduling, zero provider effects before due time, due-cron transition, idempotency matrix: unchanged replay/double-submit/stale-tab-conflict, partial delivery with Instagram delivered + Bluesky failed, safe retryable state). The old file's `ec_get_blog_id()` single-site stub is removed entirely -- the real function (extrachill-network, network-active) is already loaded. |
| `chris-gardner-social-operator-reload.php` | `grade.php` (the journey's `grade` phase) | Same final scenario matrix (safe retry to fully-delivered, exactly-once-per-platform provider effects, final share history with consistent attribution, media reuse across drafts vs. duplicate-inside-one-operation rejection, real Instagram comments read/reply against the deterministic provider stub). Grading now uses this rig's own case/finding convention (`EXTRACHILL_JOURNEY_RESULT` marker), not the old file's typed `wp_upload_dir()` ledger files -- see "What did not carry over" below. |
| `chris-gardner-social-operator-provider-stub.php` | `provider-stub.php` (journey `fixtureMuPlugins`) | Unchanged in substance: the same fail-closed `pre_http_request` boundary recording sanitized provider calls (method/host/path/classification, never tokens/bodies/secrets) for Instagram and Bluesky only, blocking every other host. Mounted generically by this rig's `fixtureMuPlugins` journey capability -- the vendor-specific stub content lives entirely in this file, never in `run.mjs`. |
| `chris-gardner-social-operator-provider-contract.php` | (not ported) | A standalone PHPUnit-style contract test for the stub itself, run outside the recipe. Coverage of the stub's real behavior now comes from this journey actually exercising it end to end during a real rig run; a duplicate unit test of the same fixture was not worth carrying forward on its own. |
| `chris-gardner-social-operator-contract.test.mjs` | (not ported) | Asserted the shape of the OLD standalone recipe JSON (mounts, `extra_plugins`, `adversarialCampaigns`). This rig's own `tests/contract.test.mjs` already asserts the shape every journey (including this one) must have; a parallel per-journey contract test would duplicate that, not extend it. |
| `chris-gardner-persona.json` / `-persona-seed.php` (the short Studio smoke recipe) | (not ported) | A separate, narrower smoke (tab visibility, media selection, review submission, comments, analytics navigation) using local REST fixtures rather than the real Data Machine Socials runtime. Its coverage is a subset of what `gardner-social-operator`'s access/denial browser steps and `deliver.php`/`grade.php` already exercise for real; it did not carry unique coverage worth a second journey. |

## What did not carry over, and why (named tolerances, not silent drops)

- **The adaptive/adversarial-exploration campaign** (`adversarialCampaigns` in
  the old recipe, using `wp-codebox`'s `adversarial-recipe-campaign/v1`
  schema and a `browser-adaptive-exploration` corpus). This is a
  wp-codebox recipe-level capability, not a journey-contract concept; the
  journey contract's `steps` are a fixed, deterministic script. The old
  README already noted "the deterministic workflow completes before the
  adaptive campaign" -- this port keeps the deterministic half in full and
  does not claim the adaptive half.
- **Typed artifact ledgers** (`provider-call-ledger.json`,
  `transition-ledger.json`, `capability-gap-ledger.json`,
  `oracle-ledger.json`, `product-contract-diagnostic.json`, written to
  `wp_upload_dir()` and declared in the old recipe's `artifacts.typed`).
  This journey uses this rig's own `EXTRACHILL_JOURNEY_RESULT` marker
  convention instead (the same one `gardner-event-rsvp` uses), which already
  carries cases, findings, and (in `grade.php`'s `capability_gaps` field)
  the carried-over capability-gap list. A generic "typed artifact"
  capability for the journey contract would need to be justified by more
  than one consumer before it is worth adding to `run.mjs`; nothing here
  needed it badly enough on its own.

## Known capability gaps (carried over, re-verify against each run)

`grade.php` re-emits the capability-gap list the original investigation
found (Studio's multi-platform composer UI, scheduling timezone, persistent
queue, published-inventory reconciliation, per-media history, Instagram
DMs, account management, unified analytics, share-initiator attribution).
These are **not re-derived from scratch** by this port -- they are carried
forward as an explicit, named list pending re-verification against a real
run in THIS rig. See `evidence/FINDINGS.md` for what this port's own run
actually confirmed, and file (or re-link) each in its owning repo after a
duplicate search, same as every other journey.
