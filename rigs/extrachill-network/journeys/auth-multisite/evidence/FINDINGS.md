# Auth-multisite journey — findings (extrachill-network#293)

Run against a real, disposable 11-site network boot (`node
rigs/extrachill-network/run.mjs`, `extrachill_journeys: ["auth-multisite"]`),
every component from its owning repo's latest release except
`extrachill-network` itself (this branch). 35 assertions, 21 passed, 14
recorded as findings below. Full machine-readable result embedded in the
journey's own `EXTRACHILL_JOURNEY_RESULT` marker (captured from the real
run's `wordpress.run-php` step output).

## Real product bug found and fixed during this port

**`extrachill_users_send_moderation_email()` fatals when
`ec_send_email_queued()` returns a `WP_Error`.** Filed and fixed in the
harness: [Extra-Chill/extrachill-users#432](https://github.com/Extra-Chill/extrachill-users/issues/432).
The moderation STATE persists (via `update_user_meta()`) before the crashed
email step, so `seed.php` wraps the call in `try/catch (\Throwable)` and the
journey's real assertions (`extrachill_users_is_blocked()`) still hold. The
crash itself is recorded as its own open finding
(`moderation-email-does-not-crash-on-a-queue-error`) rather than silently
worked around.

## Real, confirmed rig/environment gaps fixed during this port

- **extrachill-api's public-write admission gate hard-requires a real
  external object cache.** `inc/middleware/public-write-admission.php`'s
  default rate-limit store (`extrachill_api_atomic_rate_limit_cache_increment()`)
  calls `wp_using_ext_object_cache()` and fails closed with a 503 otherwise.
  This rig's own README already documents `redis-cache` as an excluded
  component (no Redis server in the disposable sandbox) -- this is the same
  gap surfacing through a different subsystem. Fixed with a site-option-backed
  substitute store via the existing `extrachill_api_rate_limit_store` filter,
  scoped to this journey's own `fixtureMuPlugins` mount (never reaches
  production).
- **`wordpress.run-php`'s eval context has no `$_SERVER['REMOTE_ADDR']`.**
  The same admission gate also fails closed with a 503 when it cannot
  resolve a valid client IP. `grade.php` now seeds a fixed, obviously-synthetic
  local address before making REST calls.

## Open finding: public-write REST calls still 503 after both fixes

Despite both fixes above, every REST call that exercises
`extrachill_api_check_public_write_rate_limit()` -- register, duplicate
registration, direct login via REST, refresh, logout-another-device -- still
returns HTTP 503 in this run, including from the REAL browser-driven
registration attempt (a genuine HTTP round-trip through WP Codebox's
Playground network, which does carry a real `REMOTE_ADDR`). This means the
two fixes above were real, necessary, and independently confirmed (each
addresses a documented, reproducible fail-closed branch in the admission
gate), but something else in that same code path still fails closed in this
disposable sandbox. The exact `WP_Error` code was not captured in this run
(only the HTTP status) -- only `evidence.status` is recorded per case, not
the underlying error code -- so the specific remaining branch is not yet
isolated.

**This is recorded as an open finding, not silently converted to a pass or
a rig workaround.** Direct authentication (`wp_authenticate()`, no REST
involved) also failed in this run
(`existing-persona-authenticates-directly`); given every REST-mediated case
fails identically regardless of call shape, this is very likely the same
root cause reached through a different path (e.g. a shared precondition the
admission gate or a related early hook enforces), not an unrelated third
bug, though that is not yet confirmed. A duplicate search of
`Extra-Chill/extrachill-api` open issues did not surface an existing report
for this specific combination; filing depends on isolating the exact
`WP_Error` code first rather than filing a same-symptom report with an
already-ruled-out cause.

## What passed cleanly

The REST invariants that do NOT depend on the public-write admission
gate all passed on the first fully-fixed run: invalid-registration rejection
(all 6 cases, matching the seeded fuzz plan), login enumeration resistance,
moderated-user token denial, anonymous-route denial, browser-handoff unsafe-redirect
rejection (all 5 cases), onboarding scoping and duplicate-completion rejection,
login redirect safety (all 7 cases), and the real login rate limiter
(Extra Chill Users' own transient-backed store, untouched) engaging after 5 bad
attempts and covering the email alias. The community login/onboarding
fixture pages (with the real blocks) render correctly on the real
`community.extrachill.com` domain.

## Cross-site continuity via the real browser-handoff mechanism

Not exercised in this run because the browser-driven registration itself did
not complete (see the 503 finding above) -- there is no browser-registered
user to mint a handoff token for. The mechanism itself
(`extrachill_users_create_browser_handoff_token()` +
`admin-post.php?action=extrachill_browser_handoff`, driven over real HTTP
against `artist.extrachill.com`/`events.extrachill.com`) is implemented and
ready to grade the moment registration succeeds; see `grade.php` and the
journey `README.md`'s "Cross-site continuity" section for what it verifies
and why it deliberately does not claim the old path-based shared-cookie
parity.
